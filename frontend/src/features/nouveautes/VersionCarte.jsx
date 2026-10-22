import { TYPES_ELEMENT, formaterDate } from './typesNouveautes'

/**
 * Une version des Nouveautés : numéro, date, titre, description, éléments.
 *
 * Partagée par la page de lecture, la modale d'annonce et la console (qui y ajoute ses
 * actions via `actions`). Tout est rendu comme du TEXTE par React — aucun contenu saisi
 * n'est interprété comme du HTML.
 */
export function PastilleType({ type }) {
  const t = TYPES_ELEMENT[type] ?? { libelle: type, style: TYPES_ELEMENT.amelioration.style }
  return (
    <span className="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold" style={t.style}>
      {t.libelle}
    </span>
  )
}

export function ListeElements({ elements }) {
  if (!elements?.length) return null

  return (
    <ul className="mt-3 space-y-2">
      {elements.map((e) => (
        <li key={e.id ?? e.titre} className="flex items-start gap-2.5">
          <PastilleType type={e.type} />
          <div className="min-w-0 text-sm">
            <div className="font-semibold text-heading">{e.titre}</div>
            {e.description && <p className="whitespace-pre-line break-words text-muted">{e.description}</p>}
          </div>
        </li>
      ))}
    </ul>
  )
}

/** `pastilleEtat` : la console montre Active / Inactive ; le lecteur ne voit que l'actif. */
export default function VersionCarte({ version, pastilleEtat = false, actions = null, pied = null }) {
  return (
    <article className="card rounded-2xl p-5" style={pastilleEtat && !version.active ? { opacity: 0.75 } : undefined}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-muted">
            <span className="rounded-full px-2 py-0.5 font-bold" style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}>
              Version {version.numero}
            </span>
            <span>{formaterDate(version.date_sortie)}</span>
            {pastilleEtat && (
              <span
                className="rounded-full px-2 py-0.5 font-bold"
                style={version.active
                  ? { background: 'var(--info-bg)', color: 'var(--info-text)' }
                  : { background: 'var(--surface-2)', color: 'var(--muted-text)' }}
              >
                {version.active ? 'Active' : 'Inactive'}
              </span>
            )}
          </div>
          <h2 className="mt-2 text-lg font-bold text-heading">{version.titre}</h2>
        </div>
        {actions && <div className="flex shrink-0 gap-1">{actions}</div>}
      </div>
      {version.description && (
        <p className="mt-1 whitespace-pre-line break-words text-sm text-muted">{version.description}</p>
      )}
      <ListeElements elements={version.elements} />
      {pied}
    </article>
  )
}
