module.exports = {
  apps: [{
    name: "znak-ws-proxy",
    script: "server.js",
    env: {
      INTERNAL_TOKEN: "f45b23999dd8a128fdcd201e0cb2805d0c6875f4427340d7",
      PORT: 8091,
      HOST: "127.0.0.1"
    }
  }]
};
