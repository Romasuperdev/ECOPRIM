import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, CheckCircle2, CloudOff, Delete, Save, Send } from 'lucide-react'
import { apiEvaluationsNotees } from './portailEnseignantApi'
import { PastilleStatutEvaluation } from './NotesClasse'

/*
 * Saisie d'une évaluation au pavé numérique, pensée pour enchaîner les copies.
 *
 * On touche un élève (ou on commence par le premier), on tape la note, « OK » passe au
 * suivant. « Abs » le marque absent à l'évaluation. Un clavier physique marche aussi :
 * chiffres, virgule, Retour arrière, Entrée.
 *
 * La saisie en cours est gardée sur le téléphone à chaque toucher : une coupure de réseau
 * ou une fermeture d'onglet ne fait rien perdre. Elle s'efface une fois enregistrée.
 * « Enregistrer le brouillon » garde les notes hors de vue des parents ; « Publier aux
 * parents » les leur montre et les prévient.
 */
const cleLocale = (entete) => `nexora-notes-${entete}`

function lireLocal(entete) {
  try {
    return JSON.parse(localStorage.getItem(cleLocale(entete)) || 'null')
  } catch {
    return null
  }
}

function ecrireLocal(entete, valeurs) {
  try {
    if (valeurs === null) localStorage.removeItem(cleLocale(entete))
    else localStorage.setItem(cleLocale(entete), JSON.stringify(valeurs))
  } catch { /* stockage refusé : la saisie reste en mémoire */ }
}

const virgule = (n) => String(n).replace('.', ',')

/** Valeur affichée/saisie d'un élève : { texte: '15,5' } ou { absent: true } ou {}. */
function depuisServeur(eleves) {
  return Object.fromEntries(eleves.map((e) => [
    e.matricule,
    e.absent ? { absent: true } : (e.note !== null ? { texte: virgule(e.note) } : {}),
  ]))
}

function versServeur(valeurs) {
  return Object.entries(valeurs)
    .filter(([, v]) => v.absent || (v.texte ?? '') !== '')
    .map(([matricule, v]) => (v.absent
      ? { matricule, note: null, absent: true }
      : { matricule, note: Number(v.texte.replace(',', '.')), absent: false }))
}

export default function SaisieEvaluationPage() {
  const { entete } = useParams()
  const { data: feuille, isLoading, isError } = useQuery({
    queryKey: ['evaluation-notee', entete],
    queryFn: () => apiEvaluationsNotees.feuille(entete),
  })

  if (isLoading) return <p className="text-muted">Chargement…</p>
  if (isError || !feuille) return <p className="card px-4 py-10 text-center text-sm text-muted">Évaluation introuvable.</p>

  // La clé repart de zéro si l'on passe à une autre évaluation.
  return <Saisie key={feuille.entete} feuille={feuille} />
}

function Saisie({ feuille }) {
  const qc = useQueryClient()
  const serveur = useMemo(() => depuisServeur(feuille.eleves), [feuille])
  const locale = lireLocal(feuille.entete)
  const [valeurs, setValeurs] = useState(() => locale ?? serveur)
  const [repriseLocale, setRepriseLocale] = useState(Boolean(locale))
  const [courant, setCourant] = useState(() => feuille.eleves.find((e) => !(locale ?? serveur)[e.matricule]?.texte && !(locale ?? serveur)[e.matricule]?.absent)?.matricule ?? feuille.eleves[0]?.matricule)
  const [erreur, setErreur] = useState(null)
  const [resultat, setResultat] = useState(null)
  const [envoi, setEnvoi] = useState(false)
  const [confirmer, setConfirmer] = useState(false)

  const bareme = Number(feuille.bareme)
  const ordre = useMemo(() => feuille.eleves.map((e) => e.matricule), [feuille])
  const notes = feuille.eleves.filter((e) => valeurs[e.matricule]?.absent || (valeurs[e.matricule]?.texte ?? '') !== '').length

  const changer = useCallback((matricule, valeur) => {
    setResultat(null)
    setValeurs((p) => {
      const suivant = { ...p, [matricule]: valeur }
      ecrireLocal(feuille.entete, suivant)
      return suivant
    })
  }, [feuille.entete])

  const suivant = useCallback(() => {
    const i = ordre.indexOf(courant)
    setCourant(ordre[Math.min(i + 1, ordre.length - 1)])
  }, [ordre, courant])

  const touche = useCallback((t) => {
    if (!courant) return
    setErreur(null)
    const v = valeurs[courant] ?? {}
    const texte = v.absent ? '' : (v.texte ?? '')
    if (t === 'ok') { suivant(); return }
    if (t === 'abs') { changer(courant, v.absent ? {} : { absent: true }); if (!v.absent) suivant(); return }
    if (t === 'del') { changer(courant, { texte: texte.slice(0, -1) }); return }
    if (t === ',') { if (!texte.includes(',')) changer(courant, { texte: (texte || '0') + ',' }); return }
    const nouveau = texte === '0' ? t : texte + t
    const decimales = nouveau.split(',')[1]
    if (decimales && decimales.length > 2) return
    if (Number(nouveau.replace(',', '.')) > bareme) {
      setErreur(`La note ne peut pas dépasser ${virgule(bareme)}.`)
      return
    }
    changer(courant, { texte: nouveau })
  }, [courant, valeurs, bareme, changer, suivant])

  // Clavier physique (ordinateur, clavier Bluetooth).
  useEffect(() => {
    const surTouche = (e) => {
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return
      if (/^[0-9]$/.test(e.key)) touche(e.key)
      else if (e.key === ',' || e.key === '.') touche(',')
      else if (e.key === 'Backspace') touche('del')
      else if (e.key === 'Enter') touche('ok')
      else return
      e.preventDefault()
    }
    window.addEventListener('keydown', surTouche)
    return () => window.removeEventListener('keydown', surTouche)
  }, [touche])

  const enregistrer = async (publier) => {
    setErreur(null)
    setConfirmer(false)
    setEnvoi(true)
    try {
      const r = await apiEvaluationsNotees.enregistrer(feuille.entete, versServeur(valeurs), publier)
      ecrireLocal(feuille.entete, null)
      setRepriseLocale(false)
      setResultat({ publier, prevenus: r.parents_prevenus })
      qc.setQueryData(['evaluation-notee', String(feuille.entete)], r.feuille)
      qc.invalidateQueries({ queryKey: ['evaluations-notees', feuille.classe] })
    } catch (e) {
      if (!e.response) {
        setErreur('Pas de réseau : les notes restent gardées sur ce téléphone. Réessayez quand la connexion revient.')
      } else {
        const errs = e.response.data?.errors
        setErreur(errs ? Object.values(errs).flat()[0] : (e.response.data?.message ?? 'Enregistrement impossible.'))
      }
    } finally {
      setEnvoi(false)
    }
  }

  const publiee = feuille.statut === 'publiee'
  const eleveCourant = feuille.eleves.find((e) => e.matricule === courant)
  const valeurCourante = valeurs[courant] ?? {}

  return (
    <div>
      <Link
        to={`/mon-espace/classes/${encodeURIComponent(feuille.classe)}?onglet=notes`}
        className="mb-3 inline-flex items-center gap-1.5 text-sm font-bold text-muted hover:text-heading"
      >
        <ArrowLeft size={15} strokeWidth={2.5} /> Notes de la classe
      </Link>

      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h1 className="truncate text-xl font-extrabold text-heading">{feuille.titre}</h1>
          <p className="text-sm text-muted">{feuille.matiere_libelle} · noté sur {virgule(bareme)} · {notes}/{feuille.eleves.length} saisis</p>
        </div>
        <PastilleStatutEvaluation statut={feuille.statut} />
      </div>

      {repriseLocale && (
        <div className="mb-3 flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold"
          style={{ background: 'var(--info-bg)', borderColor: 'var(--info-border)', color: 'var(--info-text)' }}>
          <CloudOff size={17} className="shrink-0" />
          <span className="flex-1">Saisie non enregistrée retrouvée sur ce téléphone.</span>
          <button type="button" className="font-extrabold underline" onClick={() => {
            ecrireLocal(feuille.entete, null); setValeurs(serveur); setRepriseLocale(false)
          }}>Abandonner</button>
        </div>
      )}

      <ul className="mb-3 space-y-1.5">
        {feuille.eleves.map((e) => {
          const v = valeurs[e.matricule] ?? {}
          const actif = e.matricule === courant
          return (
            <li key={e.matricule}>
              <button
                type="button" onClick={() => { setErreur(null); setCourant(e.matricule) }}
                className="flex min-h-[52px] w-full items-center gap-3 rounded-xl border-2 px-3 py-2 text-left"
                style={{ background: 'var(--card)', borderColor: actif ? 'var(--brand-accent)' : 'var(--border)' }}
                aria-current={actif ? 'true' : undefined}
              >
                <span className="min-w-0 flex-1 truncate font-semibold text-heading">{e.nom} {e.prenom}</span>
                <span
                  className="min-w-[64px] rounded-lg px-2 py-1 text-center text-base font-extrabold"
                  style={v.absent
                    ? { background: 'var(--warning-bg)', color: 'var(--warning-text)' }
                    : { background: 'var(--surface-2)', color: 'var(--heading)' }}
                >
                  {v.absent ? 'Abs' : (v.texte || '—')}
                </span>
              </button>
            </li>
          )
        })}
      </ul>

      {/* Pavé numérique, au-dessus de la navigation du téléphone. */}
      <div
        className="sticky bottom-[calc(68px+env(safe-area-inset-bottom))] rounded-2xl border p-3 shadow-xl md:bottom-4"
        style={{ background: 'var(--card)', borderColor: 'var(--border)' }}
      >
        {eleveCourant && (
          <div className="mb-2 flex items-center justify-between gap-2">
            <span className="truncate text-sm font-bold text-heading">{eleveCourant.prenom} {eleveCourant.nom}</span>
            <span className="text-2xl font-extrabold text-heading">
              {valeurCourante.absent ? 'Absent' : (valeurCourante.texte || '—')}
              <span className="text-sm font-bold text-muted"> /{virgule(bareme)}</span>
            </span>
          </div>
        )}
        {erreur && <p className="mb-2 text-sm font-semibold" style={{ color: 'var(--danger-text)' }}>{erreur}</p>}
        {resultat && (
          <p className="mb-2 flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--success-text)' }}>
            <CheckCircle2 size={16} />
            {resultat.publier
              ? `Publiée aux parents${resultat.prevenus ? ` : ${resultat.prevenus} parent${resultat.prevenus > 1 ? 's' : ''} prévenu${resultat.prevenus > 1 ? 's' : ''}` : ''}.`
              : 'Brouillon enregistré. Les parents ne le voient pas encore.'}
          </p>
        )}

        <div className="grid grid-cols-4 gap-1.5">
          {['7', '8', '9', 'del', '4', '5', '6', 'abs', '1', '2', '3', 'ok', ',', '0'].map((t) => (
            <button
              key={t} type="button" onClick={() => touche(t)}
              className={`flex h-12 items-center justify-center rounded-xl text-xl font-extrabold transition active:scale-95 ${t === 'ok' ? 'row-span-2 h-auto' : ''} ${t === '0' ? 'col-span-2' : ''}`}
              style={t === 'ok'
                ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }
                : t === 'abs'
                  ? { background: 'var(--warning-bg)', color: 'var(--warning-text)', fontSize: '0.95rem' }
                  : { background: 'var(--surface-2)', color: 'var(--heading)' }}
              aria-label={{ del: 'Effacer', abs: 'Absent à l’évaluation', ok: 'Valider et passer au suivant', ',': 'Virgule' }[t] ?? t}
            >
              {t === 'del' ? <Delete size={20} /> : t === 'abs' ? 'Abs' : t === 'ok' ? 'OK' : t}
            </button>
          ))}
        </div>

        {confirmer ? (
          <div className="mt-3 rounded-xl p-3 text-sm" style={{ background: 'var(--warning-bg)', color: 'var(--warning-text)' }}>
            <p className="font-semibold">
              {feuille.eleves.length - notes} élève{feuille.eleves.length - notes > 1 ? 's n’ont' : ' n’a'} pas encore de note. Publier quand même ?
            </p>
            <div className="mt-2 flex gap-2">
              <button type="button" onClick={() => enregistrer(true)} className="flex-1 rounded-lg px-3 py-2 font-extrabold"
                style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}>Publier</button>
              <button type="button" onClick={() => setConfirmer(false)} className="flex-1 rounded-lg px-3 py-2 font-bold">Continuer la saisie</button>
            </div>
          </div>
        ) : (
          <div className="mt-3 grid grid-cols-2 gap-2">
            <button
              type="button" disabled={envoi} onClick={() => enregistrer(publiee)}
              className="flex items-center justify-center gap-1.5 rounded-xl px-3 py-3 text-sm font-extrabold disabled:opacity-60"
              style={{ background: 'var(--surface-2)', color: 'var(--heading)' }}
            >
              <Save size={16} /> {publiee ? 'Enregistrer' : 'Brouillon'}
            </button>
            {!publiee ? (
              <button
                type="button" disabled={envoi}
                onClick={() => (notes < feuille.eleves.length ? setConfirmer(true) : enregistrer(true))}
                className="flex items-center justify-center gap-1.5 rounded-xl px-3 py-3 text-sm font-extrabold disabled:opacity-60"
                style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
              >
                <Send size={16} /> Publier aux parents
              </button>
            ) : (
              <span className="flex items-center justify-center text-center text-xs font-semibold text-muted">
                Visible des parents
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
