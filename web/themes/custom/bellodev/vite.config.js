import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';
import { copyFileSync, mkdirSync, readdirSync, existsSync } from 'fs';
import { join } from 'path';

function copyDir(src, dest) {
  if (!existsSync(src)) return;
  mkdirSync(dest, { recursive: true });
  const entries = readdirSync(src, { withFileTypes: true });

  for (const entry of entries) {
    const srcPath = join(src, entry.name);
    const destPath = join(dest, entry.name);

    if (entry.isDirectory()) {
      copyDir(srcPath, destPath);
    } else {
      copyFileSync(srcPath, destPath);
    }
  }
}

export default defineConfig({
  plugins: [
    tailwindcss(),
    {
      name: 'copy-static-assets',
      writeBundle() {
        ['images', 'fonts'].forEach((folder) => {
          copyDir(
            resolve(__dirname, `assets/src/${folder}`),
            resolve(__dirname, `assets/dist/${folder}`)
          );
        });
      },
    },
  ],

  root: 'assets/src',

  build: {
    outDir: '../dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'assets/src/js/main.js'),
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name].js',
        assetFileNames: (assetInfo) => {
          if (/\.css$/.test(assetInfo.name)) return 'css/[name][extname]';
          if (/\.(gif|jpe?g|png|svg|webp)$/.test(assetInfo.name)) return 'images/[name][extname]';
          if (/\.(woff2?|eot|ttf|otf)$/.test(assetInfo.name)) return 'fonts/[name][extname]';
          return '[name][extname]';
        },
      },
    },
  },
});