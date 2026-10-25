/**
 * Libellé de champ, à la manière des formulaires parcauto : en couleur de marque, et
 * l'astérisque d'un champ obligatoire en rouge.
 *
 * Les écrans écrivent déjà « Nom * » : plutôt que de leur ajouter à tous une propriété
 * `obligatoire`, on reconnaît ici l'astérisque final et on le met en forme.
 */
export default function Libelle({ htmlFor, children }) {
  if (!children) return null

  const texte = typeof children === 'string' ? children : null
  const obligatoire = texte !== null && /\s*\*$/.test(texte)

  return (
    <label htmlFor={htmlFor} className="libelle-champ">
      {obligatoire ? texte.replace(/\s*\*$/, '') : children}
      {obligatoire && <span className="obligatoire"> *</span>}
    </label>
  )
}

/** Message d'erreur sous un champ. */
export function ErreurChamp({ children }) {
  if (!children) return null
  return <p className="mt-1 text-xs font-medium" style={{ color: 'var(--danger-text)' }}>{children}</p>
}
