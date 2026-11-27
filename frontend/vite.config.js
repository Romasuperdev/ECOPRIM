import path from 'node:path'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Configuration Vite - Frontend ECOPRIM
//
// La forme fonction donne accès à `command` : certaines options ne valent qu'au BUILD, et
// les appliquer aussi au serveur de développement y casserait des choses (voir `base`).
/*
 * Manifeste de l'application installable (PWA), écrit au build.
 *
 * Pas un fichier de public/ : sa RACINE (start_url, scope) n'est pas le dossier des fichiers.
 * En mode « une seule adresse », les fichiers sont sous /spa/ mais les pages à la racine ; sur
 * bacou.info, tout est sous /ecole-primaire/. La racine des pages est celle du routeur
 * (VITE_BASENAME), les icônes suivent `base`.
 */
function manifestePwa(env) {
  const racine = `${(env.VITE_BASENAME || '').replace(/\/$/, '')}/`
  let base = '/'
  const contenu = () => JSON.stringify({
    name: 'NEXORA École Primaire',
    short_name: 'NEXORA',
    description: "Espace Enseignant et Parent : notes, absences, devoirs et messages de l'école.",
    lang: 'fr',
    start_url: racine,
    scope: racine,
    display: 'standalone',
    orientation: 'portrait',
    background_color: '#1e3a8a',
    theme_color: '#1e3a8a',
    icons: [
      { src: `${base}icones/icone-192.png`, sizes: '192x192', type: 'image/png', purpose: 'any' },
      { src: `${base}icones/icone-512.png`, sizes: '512x512', type: 'image/png', purpose: 'any' },
      { src: `${base}icones/icone-masquable-512.png`, sizes: '512x512', type: 'image/png', purpose: 'maskable' },
    ],
  }, null, 2)

  return {
    name: 'nexora-manifeste-pwa',
    configResolved(config) { base = config.base },
    configureServer(server) {
      server.middlewares.use('/manifest.webmanifest', (_req, res) => {
        res.setHeader('Content-Type', 'application/manifest+json')
        res.end(contenu())
      })
    },
    transformIndexHtml() {
      return [{ tag: 'link', attrs: { rel: 'manifest', href: `${base}manifest.webmanifest` }, injectTo: 'head' }]
    },
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: 'manifest.webmanifest', source: contenu() })
    },
  }
}

export default defineConfig(({ command, mode }) => ({
  plugins: [react(), tailwindcss(), manifestePwa(loadEnv(mode, import.meta.dirname, 'VITE_'))],

  // En PRODUCTION, les fichiers construits sont servis sous /spa par Apache, depuis le
  // dossier public de Laravel. Une seule origine pour l'interface et l'API : ni CORS, ni
  // cookie inter-domaines, ni URL d'API à réécrire selon le serveur — exactement ce que le
  // proxy fait déjà en développement.
  //
  // Pourquoi un sous-dossier et non `public/` directement : Apache sert index.html avant
  // index.php (DirectoryIndex), et Laravel ne verrait plus passer une seule requête. Ici
  // les fichiers construits restent à part, et c'est une route Laravel qui rend la page.
  //
  // UNIQUEMENT au build : appliqué aussi au serveur de développement, il déplacerait
  // l'application sous /spa/ et la racine redirigerait — ce qu'on ne veut pas en local.
  base: command === 'build' ? '/spa/' : '/',

  build: {
    outDir: '../backend/public/spa',
    // Le dossier ne contient que du construit : on le vide à chaque fois pour ne pas
    // laisser traîner les empreintes des versions précédentes.
    emptyOutDir: true,
  },
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  server: {
    // Port FIGÉ. Sans strictPort, Vite glisse au port suivant quand celui-ci est pris —
    // sans rien dire. L'origine change alors sous les pieds de l'application : c'est ce qui
    // faisait échouer CORS, et c'est ce qui invaliderait l'adresse d'un tunnel, qui porte
    // le port dans son nom d'hôte. Mieux vaut un démarrage qui refuse franchement.
    // 5174 et non 5173 : sur ce poste, 5173 est occupé par un autre projet.
    port: 5174,
    strictPort: true,

    // Écoute sur toutes les interfaces, pas seulement sur ::1. Par défaut Vite s'attache à
    // « localhost », que Windows résout d'abord en IPv6 : 127.0.0.1:5174 ne répondait pas,
    // et un tunnel — qui s'y connecte en IPv4 — n'aurait rien trouvé.
    host: true,

    // Hôtes acceptés. Vite refuse par défaut toute requête dont l'en-tête Host lui est
    // inconnu — protection contre le DNS rebinding. Un tunnel de développement présente
    // justement un hôte étranger (47spjxx1-5173.uks1.devtunnels.ms) : sans cette ligne,
    // il répond « Blocked request » et jamais l'application.
    allowedHosts: ['.devtunnels.ms', '.ngrok-free.app', '.loca.lt'],

    proxy: {
      // Le front et l'API passent par la MÊME origine : le navigateur n'appelle que le
      // serveur Vite, qui relaie vers Laravel. Un seul tunnel suffit donc à exposer
      // l'application entière — et il n'y a ni CORS, ni cookie inter-domaines, ni URL
      // d'API à réécrire selon l'endroit d'où l'on se connecte.
      '/api': {
        target: 'http://localhost:8001',
        changeOrigin: true,
      },
      // Sanctum pose son cookie CSRF ici, hors du préfixe /api : sans cette entrée,
      // l'appel partirait vers le serveur Vite, qui ne saurait qu'en faire, et aucune
      // connexion ne serait possible.
      '/sanctum': {
        target: 'http://localhost:8001',
        changeOrigin: true,
      },
    },
  },
}))
