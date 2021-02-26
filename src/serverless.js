const { Component } = require('@serverless/core')
const { ApiTypeError, ApiError } = require('tencent-component-toolkit/lib/utils/error')
const { sleep } = require('@ygkit/request')
const { generateId, getCodeZipPath, uploadCodeToCos } = require('./utils')
const {
  invokeFaas,
  deployFaas,
  removeFaas,
  deployApigw,
  removeApigw,
  deployVpc,
  removeVpc,
  deployCfs,
  removeCfs,
  deployDatabase,
  removeDatabase,
  deployLayer,
  removeLayer,
  deployCdn,
  removeCdn
} = require('./utils/sdk')
const DEFAULT_CONFIGS = require('./config')

class ServerlessComponent extends Component {
  getCredentials() {
    const { tmpSecrets } = this.credentials.tencent

    if (!tmpSecrets || !tmpSecrets.TmpSecretId) {
      throw new ApiTypeError(
        'CREDENTIAL',
        'Cannot get secretId/Key, your account could be sub-account and does not have the access to use SLS_QcsRole, please make sure the role exists first, then visit https://cloud.tencent.com/document/product/1154/43006, follow the instructions to bind the role to your account.'
      )
    }

    return {
      SecretId: tmpSecrets.TmpSecretId,
      SecretKey: tmpSecrets.TmpSecretKey,
      Token: tmpSecrets.Token
    }
  }

  getAppId() {
    return this.credentials.tencent.tmpSecrets.appId
  }

  initialize() {
    this.CONFIGS = DEFAULT_CONFIGS
    this.framework = 'zblogphp'
    this.__TmpCredentials = this.getCredentials()
  }

  async deploy(inputs) {
    this.initialize()

    let uuid = null
    if (!this.state.uuid) {
      uuid = generateId()
      this.state.uuid = uuid
    } else {
      ;({ uuid } = this.state)
    }

    const { framework, CONFIGS, __TmpCredentials } = this
    const region = inputs.region || CONFIGS.region
    const zone = inputs.zone || CONFIGS.zone

    console.log(`Deploying ${framework} Application (${uuid})`)

    // 1. 部署VPC
    if (inputs.vpc) {
      inputs.vpc.vpcName = `${CONFIGS.vpc.vpcName}`
      inputs.vpc.subnetName = `${CONFIGS.vpc.subnetName}-${uuid}`
    } else {
      inputs.vpc = {}
      inputs.vpc.vpcName = `${CONFIGS.vpc.vpcName}-${uuid}`
      inputs.vpc.subnetName = `${CONFIGS.vpc.subnetName}-${uuid}`
    }
    const vpcOutput = await deployVpc({
      instance: this,
      inputs,
      state: this.state.vpc
    })

    inputs.vpc = {
      vpcId: vpcOutput.vpcId,
      subnetId: vpcOutput.subnetId
    }

    this.state.vpc = vpcOutput

    // 2. 部署 cfs 和 database
    // 此处并行部署为了优化部署时间
    if (inputs.cfs) {
      inputs.cfs.fsName = `${CONFIGS.cfs.name}-${uuid}`
    } else {
      inputs.cfs = {}
      inputs.cfs.fsName = `${CONFIGS.cfs.name}-${uuid}`
    }
    const [cfsOutput, dbOutput] = await Promise.all([
      deployCfs({
        instance: this,
        inputs,
        state: this.state.cfs
      }),
      deployDatabase({
        instance: this,
        inputs,
        state: this.state.db
      })
    ])
    this.state.cfs = cfsOutput
    this.state.db = dbOutput

    // console.log('++++++++ dbOutput', dbOutput)

    // 数据库配置
    const dbConfig = {
      DB_USER: 'root',
      DB_NAME: CONFIGS.database,
      DB_PASSWORD: dbOutput.adminPassword,
      DB_HOST: dbOutput.connection.ip,
      DB_PORT: dbOutput.connection.port
    }

    // 3. 部署 zbp-installer 函数
    // zbp-installer 函数需要配置 vpc，cfs
    let installerFaasInputs = {}
    const defaultInstallerFaasInputs = {
      ...CONFIGS.zbpInstallerFaas,
      // 覆盖名称
      name: `${CONFIGS.zbpInstallerFaas.name}-${uuid}`,
      environments: [
        {
          key: 'DB_USER',
          value: dbConfig.DB_USER
        },
        {
          key: 'DB_PASSWORD',
          value: dbConfig.DB_PASSWORD
        },
        {
          key: 'DB_HOST',
          value: dbConfig.DB_HOST
        },
        {
          key: 'DB_NAME',
          value: dbConfig.DB_NAME
        }
      ]
    }
    if (inputs.faas) {
      installerFaasInputs = {
        ...inputs.faas,
        ...defaultInstallerFaasInputs
      }
    } else {
      installerFaasInputs = defaultInstallerFaasInputs
    }
    const zbpInstallerOutput = await deployFaas({
      instance: this,
      inputs: {
        ...inputs,
        ...{
          faas: installerFaasInputs
        },
        cfs: [
          {
            cfsId: cfsOutput.cfsId,
            mountInsId: cfsOutput.cfsId,
            localMountDir: CONFIGS.zbpInstallerFaas.cfsMountDir,
            remoteMountDir: '/'
          }
        ]
      },
      state: this.state.zbpInstallerFaas,
      code: {
        zipPath: CONFIGS.zbpInstallerFaas.zipPath,
        bucket: CONFIGS.bucket,
        object: `${CONFIGS.zbpInstallerFaas.name}-${uuid}.zip`
      }
    })

    this.state.zbpInstallerFaas = zbpInstallerOutput

    // 4. 上传 zblogphp 代码到 COS
    const zbpCodeZip = await getCodeZipPath({ instance: this, inputs })
    const zbpCodes = await uploadCodeToCos({
      region,
      instance: this,
      code: {
        zipPath: zbpCodeZip,
        bucket: CONFIGS.bucket,
        object: `zbp-source-code-${uuid}.zip`,
        injectShim: false,
      }
    })

    // 5. 调用 zbp-installer 函数，同步函数代码
    console.log(`Start initialize database and Z-BlogPHP code`)

    const invokeOutput = await invokeFaas({
      instance: this,
      inputs,
      name: zbpInstallerOutput.name,
      namespace: zbpInstallerOutput.namespace,
      parameters: {
        ZbpCosRegion: region,
        ZbpCosBucket: zbpCodes.bucket,
        ZbpCosPath: zbpCodes.object,

        SecretId: __TmpCredentials.SecretId,
        SecretKey: __TmpCredentials.SecretKey,
        Token: __TmpCredentials.Token
      }
    })

    if (invokeOutput.status !== 'success') {
      throw new ApiError({
        type: 'API_ZBLOGPHP_SYNC_FAAS_INVOKE_ERROR',
        message: `[INIT ERROR]: ${invokeOutput.reason}`
      })
    } else {
      const dbRetryCount = invokeOutput.syncDbRetryNumber
      console.log(
        `Initialize database zblogphp success${
          dbRetryCount > 1 ? `, retry count ${dbRetryCount}` : ''
        }`
      )
      console.log(`Sync zblogphp source code success`)
    }

    // 6. 部署 layer
    // const layerOutput = await deployLayer({
    //   instance: this,
    //   inputs: {
    //     ...inputs,
    //     ...{
    //       layer: {
    //         name: `${CONFIGS.layer.name}-${uuid}`
    //       }
    //     }
    //   },
    //   state: this.state.layer,
    //   code: {
    //     zipPath: CONFIGS.layer.zipPath,
    //     bucket: CONFIGS.bucket,
    //     object: `${CONFIGS.layer.name}-${uuid}.zip`
    //   }
    // })

    // console.log('++++++++ layerOutput', layerOutput)

    // this.state.layer = layerOutput

    // 7. 部署 zbp-server 函数
    // zbp-server 函数需要配置 vpc，cfs，环境变量
    let serverFaasInputs = {}
    const defaultServerFaasConfig = {
      ...CONFIGS.zbpServerFaas,
      // 覆盖函数名称
      name: `${CONFIGS.zbpServerFaas.name}-${uuid}`,
      environments: [
        {
          key: 'DB_NAME',
          value: dbConfig.DB_NAME
        },
        {
          key: 'DB_USER',
          value: dbConfig.DB_USER
        },
        {
          key: 'DB_PASSWORD',
          value: dbConfig.DB_PASSWORD
        },
        {
          key: 'DB_HOST',
          value: dbConfig.DB_HOST
        },
        {
          key: 'MOUNT_DIR',
          value: CONFIGS.zbpServerFaas.zbpCodeDir
        },
      ]
    }
    if (inputs.faas) {
      serverFaasInputs = {
        ...inputs.faas,
        ...defaultServerFaasConfig
      }
    } else {
      serverFaasInputs = defaultServerFaasConfig
    }
    const zbpServerOutput = await deployFaas({
      instance: this,
      inputs: {
        ...inputs,
        ...{
          faas: serverFaasInputs
        },
        // 添加 layer
        // layers: [
        //   {
        //     name: layerOutput.name,
        //     version: layerOutput.version
        //   }
        // ],
        // 添加 cfs
        cfs: [
          {
            cfsId: cfsOutput.cfsId,
            mountInsId: cfsOutput.cfsId,
            localMountDir: CONFIGS.zbpServerFaas.cfsMountDir,
            remoteMountDir: '/'
          }
        ]
      },
      state: this.state.zbpServerFaas,
      code: {
        zipPath: CONFIGS.zbpServerFaas.zipPath,
        bucket: CONFIGS.bucket,
        object: `${CONFIGS.zbpServerFaas.name}-${uuid}.zip`
      }
    })

    this.state.zbpServerFaas = zbpServerOutput

    // 8. 创建 API 网关
    if (inputs.apigw) {
      inputs.apigw.name = `${CONFIGS.apigw.name}-${uuid}`
      inputs.apigw.faas = {
        name: zbpServerOutput.name,
        namespace: zbpServerOutput.namespace
      }
    } else {
      inputs.apigw = {}
      inputs.apigw.name = `${CONFIGS.apigw.name}_${uuid}`
      inputs.apigw.faas = {
        name: zbpServerOutput.name,
        namespace: zbpServerOutput.namespace
      }
    }
    const apigwOutput = await deployApigw({
      instance: this,
      state: this.state.apigw,
      inputs
    })

    // console.log('++++++++ apigwOutput', apigwOutput)

    this.state.apigw = apigwOutput

    const outputs = {
      region,
      zone,
      vpc: vpcOutput,
      cfs: cfsOutput,
      db: dbOutput,
      apigw: apigwOutput,
      // layer: layerOutput,
      zbpInstallerFaas: zbpInstallerOutput,
      zbpServerFaas: zbpServerOutput
    }

    if (inputs.cdn) {
      const cdnOutput = await deployCdn({
        instance: this,
        state: this.state.apigw,
        inputs,
        origin: apigwOutput.domain
      })

      console.log('cdnOutput', cdnOutput)
      this.state.cdn = cdnOutput

      outputs.cdn = cdnOutput
    }

    // 这里三个单独配置，是为了支持在线调试和实时日志
    this.state.region = region
    // 配置调试函数为 zbp-server，因为它是真正 zbp 服务函数
    this.state.lambdaArn = zbpServerOutput.name
    this.state.namespace = zbpServerOutput.namespace

    await this.save()

    return outputs
  }

  async remove() {
    this.initialize()
    const { framework, state } = this
    const { region } = state

    console.log(`Removing ${framework} App`)

    // 并行 删除 zbp-installer 和 API 网关
    await Promise.all([
      removeFaas({ instance: this, region, state: state.zbpInstallerFaas }),
      removeApigw({ instance: this, region, state: state.apigw })
    ])

    // 删除 zbp-server 函数
    await removeFaas({ instance: this, region, state: state.zbpServerFaas })

    // 并行 删除 层、文件系统 和 数据库
    // 以上资源删除结束等待3s，以防后端异步逻辑未同步
    await sleep(3000)
    await Promise.all([
      // removeLayer({ instance: this, region, state: state.layer }),
      removeCfs({ instance: this, region, state: state.cfs }),
      removeDatabase({ instance: this, region, state: state.db })
    ])

    // 删除 VPC
    // 由于以上资源均依赖 VPC，所以需要最后删除
    // 以上资源删除结束等待3s，以防后端异步逻辑未同步
    await sleep(3000)
    await removeVpc({ instance: this, region, state: state.vpc })

    if (state.cdn) {
      await removeCdn({ instance: this, region, state: state.cdn })
    }

    this.state = {}

    return {}
  }
}

module.exports = ServerlessComponent
