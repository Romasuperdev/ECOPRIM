/**
 * Hiérarchie d'action du design system :
 *   primary   🔵 bleu    — l'action principale de l'écran (« Ajouter un élève »)
 *   succes    🟢 vert    — ce qui valide, confirme, clôt (« Valider l'inscription »)
 *   attention 🟠 orange  — ce qui engage et mérite un temps d'arrêt (« Publier les résultats »)
 *   danger    🔴 rouge   — ce qui détruit
 *   outline   ⚪ neutre   — tout le reste ; c'est de loin le plus fréquent, et c'est voulu :
 *                          un écran où tout est coloré n'a plus d'action principale.
 *
 * Aucune couleur littérale ici — tout passe par les rôles, sinon les boutons
 * resteraient clairs en mode sombre.
 */
const STYLES = {
  primary: { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' },
  succes: { background: 'var(--success)', color: '#ffffff' },
  attention: { background: 'var(--warning)', color: '#ffffff' },
  danger: { background: 'var(--danger)', color: '#ffffff' },
}

export default function Button({ children, variant = 'primary', className = '', ...props }) {
  const base =
    'px-4 py-2 rounded-xl font-semibold text-sm transition disabled:opacity-50 active:scale-[.98] inline-flex items-center gap-2'

  if (variant === 'outline' || variant === 'ghost') {
    return (
      <button
        className={`${base} ${className}`}
        style={{
          background: 'var(--surface-2)',
          color: 'var(--text)',
          border: '1px solid var(--border)',
        }}
        {...props}
      >
        {children}
      </button>
    )
  }

  return (
    <button className={`${base} ${className}`} style={STYLES[variant] ?? STYLES.primary} {...props}>
      {children}
    </button>
  )
}
