import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, FileText, X } from 'lucide-react'
import { dateLisible } from './dates'

/*
 * Justificatifs d'absence envoyés par les parents, et la décision à prendre.
 * Partagé par l'espace Enseignant et l'application du personnel : seule l'API change
 * (`api` = { lister, decider, fichier }).
 */
const STATUTS = {
  en_attente: { libelle: 'En attente', ton: 'warning' },
  justifiee: { libelle: 'Justifiée', ton: 'success' },
  non_justifiee: { libelle: 'Non justifiée', ton: 'danger' },
  annule: { libelle: 'Annulé', ton: 'info' },
}

export function PastilleStatut({ statut }) {
  const s = STATUTS[statut] ?? STATUTS.en_attente
  return (
    <span
      className="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-extrabold"
      style={{ background: `var(--${s.ton}-bg)`, color: `var(--${s.ton}-text)` }}
    >
      {s.libelle}
    </span>
  )
}

/** Aperçu du fichier joint, chargé par l'API (il n'est jamais public). */
export function ApercuFichier({ charger, type, nom }) {
  const [url, setUrl] = useState(null)
  const [erreur, setErreur] = useState(false)

  useEffect(() => {
    let lien = null
    let actif = true
    charger()
      .then((blob) => {
        if (!actif) return
        lien = URL.createObjectURL(blob)
        setUrl(lien)
      })
      .catch(() => actif && setErreur(true))
    return () => {
      actif = false
      if (lien) URL.revokeObjectURL(lien)
    }
  }, [charger])

  if (erreur) return <p className="text-xs text-muted">Fichier indisponible.</p>
  if (!url) return <div className="h-24 w-24 animate-pulse rounded-xl" style={{ background: 'var(--surface-2)' }} />

  if (String(type ?? '').startsWith('image/')) {
    return (
      <a href={url} target="_blank" rel="noreferrer" title="Agrandir">
        <img src={url} alt={nom ?? 'Justificatif'} className="max-h-56 rounded-xl border object-contain" style={{ borderColor: 'var(--border)' }} />
      </a>
    )
  }

  return (
    <a href={url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-sm font-bold" style={{ color: 'var(--brand-accent)' }}>
      <FileText size={16} /> {nom ?? 'Ouvrir le document'}
    </a>
  )
}

export default function ListeJustificatifs({ api, cleRequete }) {
  const [filtre, setFiltre] = useState('en_attente')
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: [cleRequete, filtre],
    queryFn: () => api.lister(filtre),
  })
  const justificatifs = data?.justificatifs ?? []

  return (
    <div>
      <div className="mb-4 flex rounded-full p-1" style={{ background: 'var(--surface-2)' }} role="tablist">
        {[['en_attente', 'À examiner'], ['tous', 'Tous']].map(([cle, libelle]) => (
          <button
            key={cle} type="button" role="tab" aria-selected={filtre === cle} onClick={() => setFiltre(cle)}
            className="flex-1 rounded-full px-3 py-1.5 text-sm font-bold"
            style={filtre === cle ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' } : { color: 'var(--muted-text)' }}
          >
            {libelle}
          </button>
        ))}
      </div>

      {isLoading && <p className="text-muted">Chargement…</p>}
      {!isLoading && justificatifs.length === 0 && (
        <p className="card px-4 py-10 text-center text-sm text-muted">
          {filtre === 'en_attente' ? 'Aucun justificatif à examiner.' : 'Aucun justificatif reçu.'}
        </p>
      )}

      <ul className="space-y-3">
        {justificatifs.map((j) => (
          <CarteJustificatif
            key={j.id} j={j} api={api}
            onDecide={() => {
              qc.invalidateQueries({ queryKey: [cleRequete] })
              qc.invalidateQueries({ queryKey: ['notifications-compteur'] })
            }}
          />
        ))}
      </ul>
    </div>
  )
}

function CarteJustificatif({ j, api, onDecide }) {
  const [commentaire, setCommentaire] = useState('')
  const decider = useMutation({
    mutationFn: (decision) => api.decider(j.id, decision, commentaire || null),
    onSuccess: onDecide,
  })
  const [charger] = useState(() => () => api.fichier(j.id))

  return (
    <li className="card p-4">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-bold text-heading">{j.eleve}</p>
          <p className="text-sm text-muted">Absence du {dateLisible(j.date_absence)}{j.classe ? ` · ${j.classe}` : ''}</p>
        </div>
        <PastilleStatut statut={j.statut} />
      </div>

      {j.message && <p className="mt-3 whitespace-pre-line rounded-xl px-3 py-2 text-sm" style={{ background: 'var(--surface-2)' }}>« {j.message} »</p>}
      {j.a_fichier && <div className="mt-3"><ApercuFichier charger={charger} type={j.fichier_type} nom={j.fichier_nom} /></div>}

      {j.statut === 'en_attente' ? (
        <div className="mt-3 space-y-2">
          <input
            className="field" placeholder="Commentaire pour le parent (facultatif)"
            value={commentaire} onChange={(e) => setCommentaire(e.target.value)} maxLength={500}
          />
          <div className="grid grid-cols-2 gap-2">
            <button
              type="button" disabled={decider.isPending} onClick={() => decider.mutate('justifiee')}
              className="flex items-center justify-center gap-1.5 rounded-xl px-3 py-3 text-sm font-extrabold disabled:opacity-60"
              style={{ background: 'var(--success-bg)', color: 'var(--success-text)', border: '1px solid var(--success-border)' }}
            >
              <Check size={16} /> Justifiée
            </button>
            <button
              type="button" disabled={decider.isPending} onClick={() => decider.mutate('non_justifiee')}
              className="flex items-center justify-center gap-1.5 rounded-xl px-3 py-3 text-sm font-extrabold disabled:opacity-60"
              style={{ background: 'var(--danger-bg)', color: 'var(--danger-text)', border: '1px solid var(--danger-border)' }}
            >
              <X size={16} /> Non justifiée
            </button>
          </div>
          {decider.isError && (
            <p className="text-sm" style={{ color: 'var(--danger-text)' }}>
              {decider.error?.response?.data?.message ?? 'Décision impossible.'}
            </p>
          )}
        </div>
      ) : (
        j.decision_par && (
          <p className="mt-3 text-xs text-muted">
            Décision de {j.decision_par}{j.commentaire ? ` : « ${j.commentaire} »` : ''}
          </p>
        )
      )}
    </li>
  )
}
