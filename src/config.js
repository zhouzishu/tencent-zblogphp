const path = require('path')

function join(p) {
  return path.join(__dirname, p)
}

const CONFIGS = {
  region: 'ap-shanghai',
  zone: 'ap-shanghai-2',

  description: 'Created by Serverless Component',

  bucket: 'zblogphp-serverless-code',

  database: 'zblogphp',

  templateUrl:
    'https://www.zblogcn.com/program/zblogphp16/',

  // cdn 配置
  cdn: {
    autoRefresh: true,
    followRedirect: {
      switch: 'on'
    },
    forceRedirect: {
      switch: 'on',
      redirectType: 'https',
      redirectStatusCode: 301
    },
    https: {
      switch: 'on',
      http2: 'on'
    }
  },

  // vpc 配置
  vpc: {
    vpcName: 'zbp-vpc',
    subnetName: 'zbp-subnet',

    cidrBlock: '10.0.0.0/16',
    enableMulticast: 'FALSE',
    enableSubnetBroadcast: 'FALSE'
  },

  // cfs 配置
  cfs: {
    name: 'zbp-cfs',
    netInterface: 'VPC',
    storageType: 'SD',
    pGroupId: 'pgroupbasic',
    protocol: 'NFS'
  },

  // zbp-installer 函数配置
  zbpInstallerFaas: {
    zipPath: join('faas/zbp-installer.zip'),
    name: 'zbp-installer',
    runtime: 'Php7',
    handler: 'sl_handler.handler',
    cfsMountDir: '/mnt',
    timeout: 120
  },

  // zbp-server 函数配置
  zbpServerFaas: {
    zipPath: join('faas/zbp-server.zip'),
    name: 'zbp-server',
    runtime: 'Php7',
    handler: 'sl_handler.handler',
    initTimeout: 30,
    cfsMountDir: '/mnt',
    zbpCodeDir: '/mnt/zbp',
    memorySize: 1024,
    timeout: 900
  },

  // 函数公共配置
  faas: {
    handler: 'sl_handler.handler',
    timeout: 10,
    initTimeout: 3,
    memorySize: 128,
    namespace: 'default',
    runtime: 'Php7'
  },

  // API 网关配置
  apigw: {
    isDisabled: false,
    name: 'zbp_apigw',
    cors: true,
    timeout: 910,
    qualifier: '$DEFAULT',
    protocols: ['https', 'http'],
    environment: 'release'
  },

  // 数据库配置
  db: {
    projectId: 0,
    dbVersion: '5.7',
    dbType: 'MYSQL',
    port: 3306,
    cpu: 1,
    memory: 1,
    storageLimit: 1000,
    instanceCount: 1,
    payMode: 0,
    dbMode: 'SERVERLESS',
    minCpu: 0.5,
    maxCpu: 2,
    autoPause: 'yes',
    autoPauseDelay: 3600 // default 1h
  },

  // COS 桶配置
  cos: {
    lifecycle: [
      {
        status: 'Enabled',
        id: 'deleteObject',
        filter: '',
        expiration: { days: '10' },
        abortIncompleteMultipartUpload: { daysAfterInitiation: '10' }
      }
    ]
  },

  cdn: {
    autoRefresh: true,
    forceRedirect: {
      switch: 'on',
      redirectType: 'https',
      redirectStatusCode: 301
    },
    https: {
      switch: 'on',
      http2: 'on'
    }
  }
}

module.exports = CONFIGS
