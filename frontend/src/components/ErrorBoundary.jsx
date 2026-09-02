import { Component } from 'react'
import { AlertTriangle } from 'lucide-react'

/**
 * Garde-fou d'affichage : une erreur d'exécution dans un écran affichait auparavant
 * une page entièrement blanche, sans indice. On montre désormais un message lisible
 * en conservant la navigation, et le détail technique reste consultable.
 *
 * `resetKey` : change de valeur (la route, par exemple) réarme le garde-fou, sinon
 * l'erreur resterait affichée après avoir navigué ailleurs.
 */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { erreur: null }
  }

  static getDerivedStateFromError(erreur) {
    return { erreur }
  }

  componentDidUpdate(prevProps) {
    if (prevProps.resetKey !== this.props.resetKey && this.state.erreur) {
      this.setState({ erreur: null })
    }
  }

  componentDidCatch(erreur, info) {
    console.error('[NEXORA] Erreur d’affichage :', erreur, info?.componentStack)
  }

  render() {
    if (!this.state.erreur) return this.props.children

    return (
      <div className="mx-auto max-w-2xl rounded-xl border border-red-200 bg-red-50 p-6">
        <div className="mb-2 flex items-center gap-2 font-semibold text-red-800">
          <AlertTriangle size={18} />
          Cet écran n’a pas pu s’afficher
        </div>
        <p className="text-sm text-red-700">
          Une erreur est survenue pendant l’affichage. Le reste de l’application reste
          utilisable : vous pouvez changer de page dans le menu.
        </p>
        <details className="mt-3">
          <summary className="cursor-pointer text-xs font-medium text-red-700">
            Détail technique
          </summary>
          <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-white/70 p-3 text-xs text-red-900">
            {String(this.state.erreur?.stack || this.state.erreur)}
          </pre>
        </details>
        <button
          type="button"
          onClick={() => this.setState({ erreur: null })}
          className="mt-4 rounded-xl border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-800 hover:bg-white/60"
        >
          Réessayer
        </button>
      </div>
    )
  }
}
