import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ThemeProvider } from 'next-themes'
import './index.css'
import App from './App.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    {/* `storageKey` doit rester accordé au script anti-clignotement de index.html.
        Par défaut, on suit le réglage du système d'exploitation. */}
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem storageKey="nexora-theme">
      <App />
    </ThemeProvider>
  </StrictMode>,
)
