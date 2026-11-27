import { useState } from 'react'
import { CalendarClock, Megaphone, Trash2 } from 'lucide-react'
import { ApercuFichier } from '../justificatifs/ListeJustificatifs'
import { dateLisible } from '../justificatifs/dates'

/*
 * Cartes d'un devoir et d'une annonce, partagées par l'espace Enseignant (avec retrait)
 * et l'espace Parent (lecture). Les photos jointes se chargent par l'API : elles ne sont
 * jamais publiques. `charger(devoirId, pieceId)` / `chargerPhoto(annonceId)` viennent de
 * l'API de l'espace concerné.
 */
function Piece({ charger, type, nom }) {
  // Fonction stable : ApercuFichier recharge le fichier quand elle change.
  const [fonction] = useState(() => charger)
  return <ApercuFichier charger={fonction} type={type} nom={nom} />
}

function quand(date) {
  if (!date) return ''
  const d = new Date(String(date).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
}

export function CarteDevoir({ devoir, charger, onRetirer }) {
  const aujourdhui = new Date().toISOString().slice(0, 10)
  const passe = devoir.date_remise && devoir.date_remise < aujourdhui

  return (
    <li className="card p-4" style={passe ? { opacity: 0.75 } : undefined}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-xs font-extrabold uppercase tracking-wide" style={{ color: 'var(--brand-accent)' }}>{devoir.matiere_libelle}</p>
          <p className="font-bold text-heading">{devoir.titre}</p>
        </div>
        {onRetirer && devoir.mien && (
          <button type="button" onClick={() => onRetirer(devoir)} className="shrink-0 p-1" aria-label="Retirer ce devoir" title="Retirer ce devoir">
            <Trash2 size={17} style={{ color: 'var(--danger)' }} />
          </button>
        )}
      </div>
      <p className="mt-1 inline-flex items-center gap-1.5 text-sm font-semibold text-muted">
        <CalendarClock size={15} /> Pour le {dateLisible(devoir.date_remise)}
      </p>
      {devoir.consigne && <p className="mt-2 whitespace-pre-line text-sm text-heading">{devoir.consigne}</p>}
      {devoir.pieces?.length > 0 && (
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
          {devoir.pieces.map((p) => (
            <Piece key={p.id} charger={() => charger(devoir.id, p.id)} type={p.type} nom={p.nom} />
          ))}
        </div>
      )}
    </li>
  )
}

export function CarteAnnonce({ annonce, chargerPhoto, onRetirer }) {
  return (
    <li className="card p-4">
      <div className="flex items-start gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style={{ background: 'var(--surface-2)', color: 'var(--brand-accent)' }}>
          <Megaphone size={17} />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-bold text-heading">{annonce.titre}</p>
          <p className="text-xs text-muted">
            {annonce.auteur ? `${annonce.auteur} · ` : ''}{quand(annonce.date)}
            {onRetirer && annonce.mienne && ` · ${annonce.parents_prevenus} parent${annonce.parents_prevenus > 1 ? 's' : ''} prévenu${annonce.parents_prevenus > 1 ? 's' : ''}`}
          </p>
        </div>
        {onRetirer && annonce.mienne && (
          <button type="button" onClick={() => onRetirer(annonce)} className="shrink-0 p-1" aria-label="Retirer cette annonce" title="Retirer cette annonce">
            <Trash2 size={17} style={{ color: 'var(--danger)' }} />
          </button>
        )}
      </div>
      {annonce.message && <p className="mt-2 whitespace-pre-line text-sm text-heading">{annonce.message}</p>}
      {annonce.a_photo && (
        <div className="mt-3"><Piece charger={() => chargerPhoto(annonce.id)} type={annonce.photo_type} nom="Photo" /></div>
      )}
    </li>
  )
}
