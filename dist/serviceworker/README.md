### Prerequisites
```shell
# 1. NODE VERSION MANAGER
# make sure to install the latest (lts) version of NodeJS
# the easiest way of doing so is by using the n manager (https://github.com/tj/n)
# and its installer (https://github.com/mklement0/n-install)
curl -L https://bit.ly/n-install | bash
n install lts
# after a directory change to .../icinga-notifications-web/dist/serviceworker
npm update && npm install

# 2. PHPSTORM SETTINGS
# open the TypeScript configuration in PhpStorm:
#   File | Settings | Languages & Frameworks | TypeScript
#   or via link: jetbrains://PhpStorm/settings?name=Languages+%26+Frameworks--TypeScript
# set the node interpreter to these settings:
#   Node interpreter: ~/n/bin/node (or the equivalent path of the 'n' manager installation)
#   Options: --target ES6
```

### Build
```shell
# the generated files are placed under ./build/{*.js|*.js.map}

# development
npm run build
# production
npm run build-prod
```

### Serve local test server
```shell
# remove the -o if you don't want to open the default browser automatically
http-server ./example -p 9216 -c-1 -o
```
