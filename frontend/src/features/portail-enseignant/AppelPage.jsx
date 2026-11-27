import { useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, CheckCircle2, CloudOff, RotateCcw, Send } from 'lucide-react'
import { fetchFeuilleAppel, envoyerAppel, fetchMesClasses } from './portailEnseignantApi'
import {
  erreurReseau, feuilleGardee, garderFeuille, identifiantEnvoi, mettreEnAttente, useFileAttenteAppels,
} from '../../lib/fileAttenteAppels'

/*
 * L'appel en 30 secondes, pensé pour le téléphone tenu d'une main.
 *
 * Tout le monde est PRÉSENT au départ. Un toucher sur un élève : ABSENT. Un deuxième :
 * RETARD. Un troisième : retour à présent. Puis « Valider l'appel » : les absences partent
 * dans ECONOMAT et les parents sont prévenus. Sans réseau, l'appel est gardé sur le
 * téléphone et part tout seul au retour de la connexion (lib/fileAttenteAppels).
 */
const SUIVANT = { present: 'absent', absent: 'retard', retard: 'present' }

const STYLES = {
  present: { libelle: 'Présent', fond: 'var(--success-bg)', encre: 'var(--success-text)', bord: 'var(--success-border)' },
  absent: { libelle: 'Absent', fond: 'var(--danger-bg)', encre: 'var(--danger-text)', bord: 'var(--danger-border)' },
  retard: { libelle: 'Retard', fond: 'var(--warning-bg)', encre: 'var(--warning-text)', bord: 'var(--warning-border)' },
}

function aujourdhui() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function creneauDuMoment() {
  return new Date().getHours() < 12 ? 'matin' : 'apres-midi'
}

function initiales(e) {
  return `${(e.prenom ?? '').trim()[0] ?? ''}${(e.nom ?? '').trim()[0] ?? ''}`.toUpperCase() || '?'
}

export default function AppelPage() {
  const { classe: classeParam } = useParams()
  const navigate = useNavigate()
  const { data: mes } = useQuery({ queryKey: ['mes-classes'], queryFn: fetchMesClasses })
  const classes = useMemo(
    () => [...new Map((mes?.classes ?? []).map((c) => [c.classe, c])).values()],
    [mes],
  )

  // Sans classe dans l'adresse : la seule classe, ou la première.
  const classe = classeParam ?? classes[0]?.classe
  useEffect(() => {
    if (!classeParam && classe) navigate(`/mon-espace/appel/${encodeURIComponent(classe)}`, { replace: true })
  }, [classeParam, classe, navigate])

  const [date, setDate] = useState(aujourdhui)
  const [creneau, setCreneau] = useState(creneauDuMoment)

  if (!classe) {
    return (
      <div>
        <RetourAccueil />
        <p className="card px-4 py-10 text-center text-sm text-muted">
          {mes ? 'Aucune classe ne vous est affectée.' : 'Chargement…'}
        </p>
      </div>
    )
  }

  return (
    <div>
      <RetourAccueil />
      <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-[1fr_auto_auto]">
        {classes.length > 1 ? (
          <select
            className="field col-span-2 sm:col-span-1"
            value={classe}
            onChange={(e) => navigate(`/mon-espace/appel/${encodeURIComponent(e.target.value)}`)}
            aria-label="Classe"
          >
            {classes.map((c) => <option key={c.classe} value={c.classe}>{c.classe_libelle ?? c.classe}</option>)}
          </select>
        ) : <div className="col-span-2 hidden sm:col-span-1 sm:block" />}
        <input
          type="date" className="field" value={date} max={aujourdhui()}
          onChange={(e) => e.target.value && setDate(e.target.value)} aria-label="Date"
        />
        <div className="flex rounded-full p-1" style={{ background: 'var(--surface-2)' }} role="radiogroup" aria-label="Créneau">
          {[['matin', 'Matin'], ['apres-midi', 'Après-midi']].map(([cle, libelle]) => (
            <button
              key={cle} type="button" role="radio" aria-checked={creneau === cle}
              onClick={() => setCreneau(cle)}
              className="flex-1 whitespace-nowrap rounded-full px-3 py-1.5 text-sm font-bold"
              style={creneau === cle ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' } : { color: 'var(--muted-text)' }}
            >
              {libelle}
            </button>
          ))}
        </div>
      </div>

      {/* La clé remet la feuille à zéro à chaque changement de classe, de jour ou de créneau. */}
      <FeuilleAppel key={`${classe}|${date}|${creneau}`} classe={classe} date={date} creneau={creneau} />
    </div>
  )
}

function RetourAccueil() {
  return (
    <Link to="/mon-espace" className="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-muted hover:text-heading">
      <ArrowLeft size={15} strokeWidth={2.5} /> Accueil
    </Link>
  )
}

function FeuilleAppel({ classe, date, creneau }) {
  const qc = useQueryClient()
  const { enAttente, refuses } = useFileAttenteAppels()
  const { data, isLoading, isError } = useQuery({
    queryKey: ['appel', classe, date, creneau],
    queryFn: () => fetchFeuilleAppel({ classe, date, creneau }),
    retry: 1,
  })

  // Hors connexion : la dernière liste de la classe gardée sur le téléphone, tous présents.
  const feuille = useMemo(() => {
    if (data) return data
    const gardee = isError ? feuilleGardee(classe) : null
    return gardee && { ...gardee, appel: null, eleves: gardee.eleves.map((e) => ({ ...e, statut: 'present' })) }
  }, [data, isError, classe])
  const attente = enAttente.find((a) => a.classe === classe && a.date === date && a.creneau === creneau)

  const [statuts, setStatuts] = useState(null)
  const [resultat, setResultat] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [envoi, setEnvoi] = useState(false)

  useEffect(() => { if (data) garderFeuille(classe, data) }, [data, classe])

  // Les statuts de départ : ceux du serveur, ou ceux d'un appel encore en attente d'envoi.
  const depart = useMemo(() => {
    if (!feuille) return null
    const s = Object.fromEntries(feuille.eleves.map((e) => [e.matricule, e.statut]))
    if (attente) {
      Object.keys(s).forEach((m) => { s[m] = 'present' })
      attente.absents.forEach((m) => { s[m] = 'absent' })
      attente.retards.forEach((m) => { s[m] = 'retard' })
    }
    return s
  }, [feuille, attente])
  const courants = statuts ?? depart

  if (isLoading) return <p className="text-muted">Chargement de la classe…</p>
  if (!feuille) {
    return (
      <p className="card px-4 py-10 text-center text-sm text-muted">
        Pas de réseau, et cette classe n’a encore jamais été ouverte sur ce téléphone.
      </p>
    )
  }

  const compte = (s) => Object.values(courants).filter((v) => v === s).length
  const basculer = (m) => {
    setResultat(null)
    setStatuts({ ...courants, [m]: SUIVANT[courants[m]] })
  }

  const valider = async () => {
    setErreur(null)
    setEnvoi(true)
    const appel = {
      classe, date, creneau, envoi: identifiantEnvoi(),
      absents: Object.keys(courants).filter((m) => courants[m] === 'absent'),
      retards: Object.keys(courants).filter((m) => courants[m] === 'retard'),
    }
    try {
      const r = await envoyerAppel(appel)
      setResultat({ ...r, horsLigne: false })
      setStatuts(null)
      qc.invalidateQueries({ queryKey: ['appel', classe, date, creneau] })
    } catch (e) {
      if (erreurReseau(e)) {
        mettreEnAttente(appel)
        setResultat({ horsLigne: true, absents: appel.absents.length, retards: appel.retards.length })
      } else {
        setErreur(e.response?.data?.message ?? 'L’appel n’a pas pu être enregistré.')
      }
    } finally {
      setEnvoi(false)
    }
  }

  const absents = compte('absent')
  const retards = compte('retard')

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2 text-sm font-bold">
        <span className="rounded-full px-3 py-1" style={{ background: STYLES.present.fond, color: STYLES.present.encre }}>{compte('present')} présents</span>
        <span className="rounded-full px-3 py-1" style={{ background: STYLES.absent.fond, color: STYLES.absent.encre }}>{absents} absents</span>
        <span className="rounded-full px-3 py-1" style={{ background: STYLES.retard.fond, color: STYLES.retard.encre }}>{retards} retards</span>
        {(absents > 0 || retards > 0) && (
          <button
            type="button" onClick={() => setStatuts(Object.fromEntries(Object.keys(courants).map((m) => [m, 'present'])))}
            className="ml-auto inline-flex items-center gap-1 text-muted"
          >
            <RotateCcw size={14} /> Tous présents
          </button>
        )}
      </div>

      {feuille.appel && !resultat && !attente && (
        <p className="mb-3 text-sm text-muted">
          Appel déjà fait{feuille.appel.auteur ? ` par ${feuille.appel.auteur}` : ''} : vous pouvez le corriger.
        </p>
      )}
      {attente && !resultat && (
        <Bandeau type="warning" icone={CloudOff}>Cet appel attend le réseau pour partir. Il sera envoyé tout seul.</Bandeau>
      )}
      {refuses.length > 0 && (
        <Bandeau type="danger">
          {refuses.length} appel(s) fait(s) hors connexion ont été refusés par le serveur : {refuses[0].motif}
        </Bandeau>
      )}
      {isError && <Bandeau type="warning" icone={CloudOff}>Pas de réseau : liste gardée sur le téléphone.</Bandeau>}

      <p className="mb-2 text-xs font-semibold text-muted">Touchez un élève : une fois pour absent, deux fois pour retard.</p>

      <ul className="space-y-2">
        {feuille.eleves.map((e) => {
          const s = STYLES[courants[e.matricule]]
          return (
            <li key={e.matricule}>
              <button
                type="button"
                onClick={() => basculer(e.matricule)}
                className="flex min-h-[64px] w-full items-center gap-3 rounded-2xl border-2 px-3 py-2 text-left transition active:scale-[.98]"
                style={{ background: 'var(--card)', borderColor: courants[e.matricule] === 'present' ? 'var(--border)' : s.bord }}
                aria-label={`${e.prenom ?? ''} ${e.nom ?? ''} : ${s.libelle}`}
              >
                <span
                  className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-extrabold"
                  style={{ background: s.fond, color: s.encre }}
                >
                  {initiales(e)}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-bold text-heading">{e.nom} {e.prenom}</span>
                </span>
                <span className="shrink-0 rounded-full px-3 py-1 text-sm font-extrabold" style={{ background: s.fond, color: s.encre }}>
                  {s.libelle}
                </span>
              </button>
            </li>
          )
        })}
      </ul>

      {/* Barre de validation, au-dessus de la navigation du téléphone. */}
      <div className="sticky bottom-[calc(68px+env(safe-area-inset-bottom))] mt-4 md:bottom-4">
        {resultat && <Resultat resultat={resultat} />}
        {erreur && <Bandeau type="danger">{erreur}</Bandeau>}
        <button
          type="button"
          onClick={valider}
          disabled={envoi || feuille.eleves.length === 0}
          className="flex w-full items-center justify-center gap-2 rounded-2xl px-4 py-4 text-base font-extrabold shadow-lg transition active:scale-[.99] disabled:opacity-60"
          style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
        >
          <Send size={18} />
          {envoi ? 'Envoi…' : `Valider l’appel${absents || retards ? ` · ${absents} absent${absents > 1 ? 's' : ''}, ${retards} retard${retards > 1 ? 's' : ''}` : ' · tous présents'}`}
        </button>
      </div>
    </div>
  )
}

function Resultat({ resultat }) {
  if (resultat.horsLigne) {
    return (
      <Bandeau type="warning" icone={CloudOff}>
        Pas de réseau : l’appel est gardé sur le téléphone et partira tout seul dès le retour de la connexion.
      </Bandeau>
    )
  }
  return (
    <Bandeau type="success" icone={CheckCircle2}>
      Appel enregistré : {resultat.absents} absent{resultat.absents > 1 ? 's' : ''}, {resultat.retards} retard{resultat.retards > 1 ? 's' : ''}.
      {resultat.parents_prevenus > 0 && ` ${resultat.parents_prevenus} parent${resultat.parents_prevenus > 1 ? 's' : ''} prévenu${resultat.parents_prevenus > 1 ? 's' : ''}.`}
    </Bandeau>
  )
}

function Bandeau({ type, icone: Icone, children }) {
  return (
    <div
      className="mb-3 flex items-start gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold"
      style={{ background: `var(--${type}-bg)`, borderColor: `var(--${type}-border)`, color: `var(--${type}-text)` }}
      role={type === 'danger' ? 'alert' : 'status'}
    >
      {Icone && <Icone size={18} className="mt-0.5 shrink-0" />}
      <span>{children}</span>
    </div>
  )
}
