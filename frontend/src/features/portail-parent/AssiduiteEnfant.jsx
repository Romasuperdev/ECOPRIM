import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Camera, Clock, Paperclip, Send } from 'lucide-react'
import { envoyerJustificatif, fetchAssiduiteEnfant, fetchFichierJustificatif } from './portailParentApi'
import { ApercuFichier, PastilleStatut } from '../justificatifs/ListeJustificatifs'
import { dateLisible } from '../justificatifs/dates'

/*
 * Espace Parent, onglet Absences : les absences et retards de l'enfant, et pour chaque
 * absence non justifiée, l'envoi d'un justificatif — un mot, une photo prise avec le
 * téléphone (certificat médical) ou un PDF.
 */
export default function AssiduiteEnfant({ matricule }) {
  const { data, isLoading } = useQuery({
    queryKey: ['portail-assiduite', matricule],
    queryFn: () => fetchAssiduiteEnfant(matricule),
  })

  if (isLoading) return <p className="text-muted">Chargement…</p>

  const absences = data?.absences ?? []
  const retards = data?.retards ?? []

  return (
    <div className="space-y-6">
      <section>
        <h2 className="mb-3 text-base font-bold text-heading">
          Absences {absences.length > 0 && <span className="text-muted">({absences.length})</span>}
        </h2>
        {absences.length === 0 && <p className="card px-4 py-8 text-center text-sm text-muted">Aucune absence cette année.</p>}
        <ul className="space-y-3">
          {absences.map((a) => <CarteAbsence key={a.code} absence={a} matricule={matricule} />)}
        </ul>
      </section>

      <section>
        <h2 className="mb-3 text-base font-bold text-heading">
          Retards {retards.length > 0 && <span className="text-muted">({retards.length})</span>}
        </h2>
        {retards.length === 0 && <p className="card px-4 py-6 text-center text-sm text-muted">Aucun retard.</p>}
        <ul className="space-y-2">
          {retards.map((r, i) => (
            <li key={`${r.date}-${r.creneau}-${i}`} className="card flex items-center gap-3 px-4 py-3 text-sm">
              <Clock size={17} style={{ color: 'var(--warning-text)' }} />
              <span className="font-semibold text-heading">{dateLisible(r.date)}</span>
              <span className="text-muted">· {r.creneau}</span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}

function CarteAbsence({ absence, matricule }) {
  const [ouvert, setOuvert] = useState(false)
  const j = absence.justificatif
  // Peut (re)justifier : absence non justifiée, et rien en attente.
  const peutJustifier = !absence.justifiee && j?.statut !== 'en_attente'
  const statut = absence.justifiee ? 'justifiee' : (j?.statut ?? 'non_justifiee')

  return (
    <li className="card p-4">
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="font-bold text-heading">{dateLisible(absence.date)}</p>
          <p className="text-sm text-muted">{absence.heure ?? 'Journée'}{absence.motif ? ` · ${absence.motif}` : ''}</p>
        </div>
        <PastilleStatut statut={statut} />
      </div>

      {j && j.statut !== 'en_attente' && j.commentaire && (
        <p className="mt-2 text-sm text-muted">Réponse de l’école : « {j.commentaire} »</p>
      )}
      {j?.statut === 'en_attente' && (
        <p className="mt-2 text-sm text-muted">Justificatif envoyé, en attente de l’école.</p>
      )}
      {j?.a_fichier && j.statut === 'en_attente' && (
        <div className="mt-2"><ApercuFichierParent id={j.id} type={j.fichier_type} nom={j.fichier_nom} /></div>
      )}

      {peutJustifier && !ouvert && (
        <button
          type="button" onClick={() => setOuvert(true)}
          className="mt-3 inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-bold"
          style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
        >
          <Paperclip size={15} /> {j?.statut === 'non_justifiee' ? 'Envoyer un autre justificatif' : 'Justifier cette absence'}
        </button>
      )}
      {peutJustifier && ouvert && (
        <FormulaireJustificatif absence={absence} matricule={matricule} onFermer={() => setOuvert(false)} />
      )}
    </li>
  )
}

function ApercuFichierParent({ id, type, nom }) {
  const [charger] = useState(() => () => fetchFichierJustificatif(id))
  return <ApercuFichier charger={charger} type={type} nom={nom} />
}

function FormulaireJustificatif({ absence, matricule, onFermer }) {
  const qc = useQueryClient()
  const [message, setMessage] = useState('')
  const [fichier, setFichier] = useState(null)

  const envoyer = useMutation({
    mutationFn: () => envoyerJustificatif(matricule, absence.code, { message: message.trim(), fichier }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['portail-assiduite', matricule] })
      onFermer()
    },
  })

  const erreurs = envoyer.error?.response?.data?.errors
  const messageErreur = erreurs
    ? Object.values(erreurs).flat()[0]
    : envoyer.error?.response?.data?.message ?? (envoyer.isError ? 'Envoi impossible. Vérifiez votre connexion.' : null)

  return (
    <form
      className="mt-3 space-y-3"
      onSubmit={(e) => { e.preventDefault(); envoyer.mutate() }}
    >
      <textarea
        className="field min-h-[90px]" maxLength={1000} value={message}
        onChange={(e) => setMessage(e.target.value)}
        placeholder="Expliquez l’absence (maladie, rendez-vous…)"
        aria-label="Mot d’explication"
      />
      <label
        className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed px-3 py-3 text-sm font-bold"
        style={{ borderColor: 'var(--border-strong)', color: 'var(--heading)' }}
      >
        <Camera size={18} />
        <span className="min-w-0 flex-1 truncate">{fichier ? fichier.name : 'Photo du certificat ou document (facultatif)'}</span>
        <input
          type="file" accept="image/*,application/pdf" className="sr-only"
          onChange={(e) => setFichier(e.target.files?.[0] ?? null)}
        />
      </label>
      {messageErreur && <p className="text-sm" style={{ color: 'var(--danger-text)' }}>{messageErreur}</p>}
      <div className="flex gap-2">
        <button
          type="submit" disabled={envoyer.isPending || (!message.trim() && !fichier)}
          className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl px-4 py-3 text-sm font-extrabold disabled:opacity-50"
          style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
        >
          <Send size={15} /> {envoyer.isPending ? 'Envoi…' : 'Envoyer à l’école'}
        </button>
        <button type="button" onClick={onFermer} className="rounded-xl px-4 py-3 text-sm font-bold text-muted">
          Annuler
        </button>
      </div>
    </form>
  )
}
