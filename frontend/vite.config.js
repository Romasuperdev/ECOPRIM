import path from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Configuration Vite - Frontend ECOPRIM
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
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
})
