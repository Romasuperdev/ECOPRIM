/**
 * Hiérarchie d'action du design system :
 *   primary   🔵 bleu    — l'action principale de l'écran (« Ajouter un élève »)
 *   succes    🔵 bleu    — ce qui valide, confirme, clôt (la charte n'a pas de vert)
 *   attention 🟤 ocre    — ce qui engage et mérite un temps d'arrêt (« Publier les résultats »)
 *   danger    🔴 rouge   — ce qui détruit
 *   outline   ⚪ neutre   — tout le reste ; c'est de loin le plus fréquent, et c'est voulu :
 *                          un écran où tout est coloré n'a plus d'action principale.
 *
 * Aucune couleur littérale ici — tout passe par les rôles, sinon les boutons
 * resteraient clairs en mode sombre.
 */
const STYLES = {
  primary: { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' },
  succes: { background: 'var(--success)', color: 'var(--brand-accent-ink)' },
  attention: { background: 'var(--warning)', color: '#ffffff' },
  danger: { background: 'var(--danger)', color: '#ffffff' },
}

export default function Button({ children, variant = 'primary', className = '', ...props }) {
  const base =
    'px-5 py-2 rounded-[var(--radius)] font-bold text-sm transition hover:brightness-110 hover:-translate-y-px disabled:opacity-50 disabled:hover:translate-y-0 active:translate-y-0 active:scale-[.98] inline-flex items-center gap-2'

  if (variant === 'outline' || variant === 'ghost') {
    return (
      <button
        className={`${base} ${className}`}
        style={{
          background: 'var(--surface)',
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
