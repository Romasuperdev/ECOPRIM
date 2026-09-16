import { useTheme } from 'next-themes'
import { Monitor, Moon, Sun } from 'lucide-react'

// Trois états plutôt que deux : « système » est le défaut et doit rester
// atteignable, sinon un utilisateur qui a touché au bouton ne peut plus rendre
// la main à son appareil. L'icône montre l'état courant, l'infobulle annonce le
// suivant — un bouton qui cycle n'est lisible qu'à cette condition.
const CYCLE = {
  system: { suivant: 'light', Icone: Monitor, titre: 'Thème : système — passer en clair' },
  light: { suivant: 'dark', Icone: Sun, titre: 'Thème : clair — passer en sombre' },
  dark: { suivant: 'system', Icone: Moon, titre: 'Thème : sombre — suivre le système' },
}

export default function BasculeTheme({ surFondSombre = false }) {
  const { theme, setTheme } = useTheme()
  const { suivant, Icone, titre } = CYCLE[theme] ?? CYCLE.system

  return (
    <button
      type="button"
      onClick={() => setTheme(suivant)}
      title={titre}
      aria-label={titre}
      className={`shrink-0 rounded-lg p-2 transition ${
        surFondSombre ? 'hover:bg-white/10' : 'hover:bg-black/5 dark:hover:bg-white/10'
      }`}
      style={{ color: surFondSombre ? 'var(--sidebar-text)' : 'var(--muted-text)' }}
    >
      <Icone size={18} />
    </button>
  )
}
