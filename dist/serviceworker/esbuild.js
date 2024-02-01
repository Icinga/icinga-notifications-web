import {build} from 'esbuild';

build({
        entryPoints: ['source/icinga-notifications-worker.ts'],
        outdir: 'build',
        bundle: true,
        sourcemap: true,
        minify: false,
        splitting: false,
        format: 'iife',
        define: { global: 'globalThis' },
        target: ['chrome58', 'firefox57', 'safari11', 'edge16'],
        globalName: 'icinga.notifications.worker',
        legalComments: 'none',
        logLevel: 'debug'
})
.catch((process) => process.exit(1));
