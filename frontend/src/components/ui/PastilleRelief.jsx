/**
 * Pastille d'icône en relief — le même volume que les rubriques du menu, réutilisable
 * partout. Le dessin lui-même vit dans `.pastille-relief` (index.css) ; ce composant
 * ne fait que poser les trois teintes et la taille.
 *
 * @param {string} module  'parametre' (bleu), 'traitement' (vert) ou 'programme'
 *   (orange). Ce sont les trois familles de couleur du design system ; un espace
 *   Enseignant ou Parent y puise comme le reste de l'application, pour que ces
 *   portails restent visiblement la même plateforme.
 * @param {'sm'|'md'|'lg'} taille
 */
const TAILLES = {
  sm: { boite: 'h-8 w-8 rounded-xl', icone: 17 },
  md: { boite: 'h-10 w-10 rounded-xl', icone: 20 },
  lg: { boite: 'h-12 w-12 rounded-2xl', icone: 24 },
}

export default function PastilleRelief({ icon: Icone, module = 'parametre', taille = 'md', className = '' }) {
  const { boite, icone } = TAILLES[taille] ?? TAILLES.md

  return (
    <span
      className={`pastille-relief flex shrink-0 items-center justify-center ${boite} ${className}`}
      style={{
        '--pastille-haut': `var(--module-${module}-haut)`,
        '--pastille-bas': `var(--module-${module}-bas)`,
        '--pastille-encre': `var(--module-${module}-encre)`,
      }}
    >
      <Icone size={icone} strokeWidth={2.6} />
    </span>
  )
}
