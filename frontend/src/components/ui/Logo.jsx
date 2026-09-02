/**
 * Marque NEXORA École Primaire.
 * `variant` : 'sidebar' (texte clair sur fond foncé) ou 'light' (texte foncé sur fond clair).
 */
export default function Logo({ size = 32, variant = 'sidebar', showText = true }) {
  const couleurTitre = variant === 'sidebar' ? '#fff' : 'var(--heading, #2a211c)'
  const couleurSous = variant === 'sidebar'
    ? 'color-mix(in srgb, var(--sidebar-text) 65%, transparent)'
    : 'var(--muted, #8a7d72)'

  return (
    <div className="flex items-center gap-2.5">
      <svg width={size} height={size} viewBox="0 0 64 64" role="img" aria-label="NEXORA" className="shrink-0">
        <rect x="2" y="2" width="60" height="60" rx="14" fill="var(--sidebar-2, #443a33)" />
        <path
          d="M20 47 L20 17 L44 47 L44 17"
          fill="none"
          stroke="var(--accent, #c08a45)"
          strokeWidth="6"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <rect x="20" y="52" width="24" height="3.5" rx="1.75" fill="var(--accent, #c08a45)" opacity="0.55" />
      </svg>

      {showText && (
        <div className="leading-tight">
          <div className="text-base font-extrabold tracking-tight" style={{ color: couleurTitre }}>
            NEXORA
          </div>
          <div className="text-[10px] font-medium uppercase tracking-wider" style={{ color: couleurSous }}>
            École Primaire
          </div>
        </div>
      )}
    </div>
  )
}
