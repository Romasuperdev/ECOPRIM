import { useEffect, useMemo, useState, useSyncExternalStore } from 'react'
import { Link } from 'react-router-dom'
import CarteFormulaire, { BoutonsFormulaire } from '../../../components/ui/CarteFormulaire'
import { ArrowDown, ArrowUp, ArrowUpDown, CheckCircle2, Pencil, Trash2, TriangleAlert, X } from 'lucide-react'

/*
 * Le « kit » des écrans de la console, calqué sur la console parcauto.
 *
 * Tous ses écrans ont la même constitution, et c'est elle qu'on reproduit ici une fois
 * pour toutes plutôt qu'écran par écran :
 *   — une carte à en-tête bleu dégradé, titre à gauche, bouton « Nouvelle … » à droite ;
 *   — un tableau à la DataTables : « Afficher N éléments », « Rechercher : », tri par
 *     colonne, « Affichage de l'élément x à y sur n », pagination Précédent / Suivant ;
 *   — des boutons Modifier (ocre) et Supprimer (rouge), carrés et soulignés d'un contour ;
 *   — un message de réussite en haut de page, qui se ferme seul au bout de 4 secondes ;
 *   — des formulaires sur leur propre page (créer, modifier), pas en fenêtre.
 * Les couleurs suivent la charte Bleu & Blanc : la constitution est celle de parcauto,
 * la palette celle de NEXORA.
 */

// ── Message de réussite / d'erreur (le « flash » de parcauto) ──────────────────

let messageCourant = null
const abonnes = new Set()
const emettre = () => abonnes.forEach((f) => f())

/** À appeler après une action réussie, juste avant de naviguer : le message suit. */
export const flash = {
  succes: (texte) => { messageCourant = { type: 'succes', texte, id: Date.now() }; emettre() },
  erreur: (texte) => { messageCourant = { type: 'erreur', texte, id: Date.now() }; emettre() },
}

export function MessageFlash() {
  const message = useSyncExternalStore(
    (f) => { abonnes.add(f); return () => abonnes.delete(f) },
    () => messageCourant,
  )

  useEffect(() => {
    if (!message) return undefined
    const minuteur = setTimeout(() => { messageCourant = null; emettre() }, 4000)
    return () => clearTimeout(minuteur)
  }, [message])

  if (!message) return null
  const succes = message.type === 'succes'

  return (
    <div
      key={message.id}
      role={succes ? 'status' : 'alert'}
      className="animate-fade-up mb-4 flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-medium shadow-sm"
      style={succes
        ? { background: 'var(--success-bg)', borderColor: 'var(--success-border)', color: 'var(--success-text)' }
        : { background: 'var(--danger-bg)', borderColor: 'var(--danger-border)', color: 'var(--danger-text)' }}
    >
      {succes ? <CheckCircle2 size={18} /> : <TriangleAlert size={18} />}
      <span className="flex-1">{message.texte}</span>
      <button type="button" onClick={() => { messageCourant = null; emettre() }} className="opacity-60 hover:opacity-100" aria-label="Fermer">
        <X size={16} />
      </button>
    </div>
  )
}

/** Extrait le message lisible d'une erreur d'API (validation 422 comprise). */
export function messageApi(e, defaut = 'L’opération a échoué.') {
  const erreurs = e?.response?.data?.errors
  if (erreurs) return Object.values(erreurs).flat()[0]
  return e?.response?.data?.message || defaut
}

// ── Carte à en-tête dégradé ──────────────────────────────────────────────────

export function CarteConsole({ icon: Icone, titre, action, children, largeur = 'max-w-6xl', corpsClassName = 'p-5' }) {
  return (
    <div className={`mx-auto ${largeur}`}>
      <MessageFlash />
      <div className="overflow-hidden rounded-2xl shadow-lg" style={{ background: 'var(--surface)' }}>
        <div
          className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 font-bold text-white"
          style={{ background: 'linear-gradient(135deg, var(--brand-surface-2), var(--brand-surface))' }}
        >
          <h1 className="m-0 flex items-center gap-2 text-lg font-bold text-white">
            {Icone && <Icone size={20} />} {titre}
          </h1>
          {action}
        </div>
        <div className={corpsClassName}>{children}</div>
      </div>
    </div>
  )
}

/** Le bouton blanc de l'en-tête (« Nouvelle Société »…) : lien si `to`, bouton sinon. */
export function BoutonEntete({ to, onClick, icon: Icone, children }) {
  const classe = 'inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-semibold shadow-sm transition hover:-translate-y-px hover:bg-slate-50'
  const style = { color: '#1f4f8a' }
  const contenu = <>{Icone && <Icone size={16} />}{children}</>
  return to
    ? <Link to={to} className={classe} style={style}>{contenu}</Link>
    : <button type="button" onClick={onClick} className={classe} style={style}>{contenu}</button>
}

// ── Boutons d'action de ligne ────────────────────────────────────────────────

const ACTION = 'inline-flex h-8 w-8 items-center justify-center rounded-md border transition hover:-translate-y-0.5 hover:shadow'

export function BoutonModifier({ to, onClick, titre = 'Modifier' }) {
  const props = {
    title: titre, 'aria-label': titre, className: `${ACTION} bouton-modifier`,
  }
  return to ? <Link to={to} {...props}><Pencil size={15} /></Link> : <button type="button" onClick={onClick} {...props}><Pencil size={15} /></button>
}

/** Demande confirmation, comme le `confirm()` de parcauto, avant d'appeler `onConfirme`. */
export function BoutonSupprimer({ onConfirme, confirmation, titre = 'Supprimer', disabled }) {
  return (
    <button
      type="button" title={titre} aria-label={titre} disabled={disabled}
      className={`${ACTION} bouton-supprimer disabled:opacity-50`}
      onClick={() => { if (window.confirm(confirmation)) onConfirme() }}
    >
      <Trash2 size={15} />
    </button>
  )
}

export function Actions({ children }) {
  return <div className="inline-flex gap-1">{children}</div>
}

/** Pastille d'état (Actif / Inactif…). */
export function Pastille({ actif, oui = 'Actif', non = 'Inactif' }) {
  return (
    <span
      className="rounded-full px-2.5 py-0.5 text-xs font-bold"
      style={actif
        ? { background: 'var(--success-bg)', color: 'var(--success-text)' }
        : { background: 'var(--surface-2)', color: 'var(--muted-text)' }}
    >
      {actif ? oui : non}
    </span>
  )
}

// ── Tableau à la DataTables ──────────────────────────────────────────────────

const TAILLES = [10, 25, 50, 100]

function texteDe(colonne, ligne) {
  const v = colonne.valeur ? colonne.valeur(ligne) : ligne[colonne.cle]
  return v == null ? '' : String(v)
}

/**
 * @param {{cle: string, titre: string, valeur?: (l) => any, rendu?: (l) => any,
 *          triable?: boolean, className?: string}[]} colonnes
 *   `valeur` sert au tri et à la recherche ; `rendu`, à l'affichage (sinon `valeur`).
 *   Une colonne d'actions se déclare avec `triable: false` et un `rendu`.
 */
export function TableConsole({ colonnes, lignes, cleLigne = (l) => l.id, vide = 'Aucun élément à afficher.', chargement = false, triInitial }) {
  const [parPage, setParPage] = useState(10)
  const [page, setPage] = useState(1)
  const [recherche, setRecherche] = useState('')
  const [tri, setTri] = useState(triInitial ?? { cle: colonnes.find((c) => c.triable !== false)?.cle, sens: 'asc' })

  const filtrees = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    let resultat = lignes ?? []
    if (q) {
      resultat = resultat.filter((l) => colonnes.some((c) => c.triable !== false && texteDe(c, l).toLowerCase().includes(q)))
    }
    const colonne = colonnes.find((c) => c.cle === tri.cle)
    if (colonne) {
      resultat = [...resultat].sort((a, b) => {
        const r = texteDe(colonne, a).localeCompare(texteDe(colonne, b), 'fr', { numeric: true, sensitivity: 'base' })
        return tri.sens === 'asc' ? r : -r
      })
    }
    return resultat
  }, [lignes, colonnes, recherche, tri])

  const pages = Math.max(1, Math.ceil(filtrees.length / parPage))
  const pageCourante = Math.min(page, pages)
  const debut = (pageCourante - 1) * parPage
  const visibles = filtrees.slice(debut, debut + parPage)

  const trier = (cle) => setTri((t) => ({ cle, sens: t.cle === cle && t.sens === 'asc' ? 'desc' : 'asc' }))

  // Pages proposées : la première, la dernière, et deux de part et d'autre de la page courante.
  const numeros = []
  for (let n = 1; n <= pages; n++) {
    if (n === 1 || n === pages || Math.abs(n - pageCourante) <= 2) numeros.push(n)
    else if (numeros[numeros.length - 1] !== '…') numeros.push('…')
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
        <label className="flex items-center gap-2 font-medium">
          Afficher
          <select
            value={parPage}
            onChange={(e) => { setParPage(Number(e.target.value)); setPage(1) }}
            className="field field--dense !w-auto"
          >
            {TAILLES.map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
          éléments
        </label>
        <label className="flex items-center gap-2 font-medium">
          Rechercher :
          <input
            type="search" value={recherche}
            onChange={(e) => { setRecherche(e.target.value); setPage(1) }}
            className="field field--dense !w-56"
          />
        </label>
      </div>

      <div className="overflow-x-auto rounded-xl border" style={{ borderColor: 'var(--border)' }}>
        <table className="w-full text-center text-sm">
          <thead>
            <tr>
              {colonnes.map((c) => (
                <th key={c.cle} className={`px-3 py-2.5 ${c.className ?? ''}`}>
                  {c.triable === false ? c.titre : (
                    <button type="button" onClick={() => trier(c.cle)} className="inline-flex items-center gap-1 uppercase">
                      {c.titre}
                      {tri.cle === c.cle
                        ? (tri.sens === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />)
                        : <ArrowUpDown size={12} className="opacity-40" />}
                    </button>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {chargement && (
              <tr><td colSpan={colonnes.length} className="px-3 py-8 text-muted">Chargement…</td></tr>
            )}
            {!chargement && visibles.length === 0 && (
              <tr>
                <td colSpan={colonnes.length} className="px-3 py-8 text-muted">
                  {recherche ? 'Aucun élément ne correspond à la recherche.' : vide}
                </td>
              </tr>
            )}
            {!chargement && visibles.map((l) => (
              <tr key={cleLigne(l)} className="border-b" style={{ borderColor: 'var(--border)' }}>
                {colonnes.map((c) => (
                  <td key={c.cle} className={`px-3 py-2 ${c.className ?? ''}`}>
                    {c.rendu ? c.rendu(l) : texteDe(c, l) || '—'}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
        <span>
          {filtrees.length === 0
            ? 'Affichage de l’élément 0 à 0 sur 0 élément'
            : `Affichage de l’élément ${debut + 1} à ${Math.min(debut + parPage, filtrees.length)} sur ${filtrees.length} élément${filtrees.length > 1 ? 's' : ''}`}
          {recherche && lignes?.length !== filtrees.length && ` (filtré de ${lignes?.length ?? 0} éléments au total)`}
        </span>
        <nav className="flex items-center gap-1" aria-label="Pagination">
          <BoutonPage disabled={pageCourante === 1} onClick={() => setPage(pageCourante - 1)}>Précédent</BoutonPage>
          {numeros.map((n, i) => n === '…'
            ? <span key={`e${i}`} className="px-1">…</span>
            : <BoutonPage key={n} actif={n === pageCourante} onClick={() => setPage(n)}>{n}</BoutonPage>)}
          <BoutonPage disabled={pageCourante === pages} onClick={() => setPage(pageCourante + 1)}>Suivant</BoutonPage>
        </nav>
      </div>
    </div>
  )
}

function BoutonPage({ actif, disabled, onClick, children }) {
  return (
    <button
      type="button" disabled={disabled} onClick={onClick}
      className="min-w-8 rounded-md border px-2.5 py-1 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-40"
      style={actif
        ? { background: 'var(--brand-accent)', borderColor: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }
        : { borderColor: 'var(--border)', color: 'var(--text)', background: 'var(--surface)' }}
    >
      {children}
    </button>
  )
}

// ── Page de formulaire (créer / modifier) ────────────────────────────────────

/**
 * Formulaire sur sa propre page, comme `create.blade.php` / `edit.blade.php` de parcauto :
 * titre + Retour, carte à en-tête dégradé, récapitulatif des erreurs, boutons Annuler
 * (gris) et Enregistrer (bleu). Le gabarit est celui de toute l'application
 * (components/ui/CarteFormulaire).
 */
export function PageFormulaire({ icon, titre, retour, onSubmit, enCours, erreur, children, libelle = 'Enregistrer', largeur = 'max-w-4xl' }) {
  return (
    <div className={`mx-auto ${largeur}`}>
      <MessageFlash />
      <CarteFormulaire icon={icon} titre={titre} retour={retour} iconeCarte={icon} erreurs={erreur ? [erreur] : []} largeur="">
        <form onSubmit={(e) => { e.preventDefault(); onSubmit() }} noValidate className="space-y-4">
          {children}
          <BoutonsFormulaire annulerVers={retour} enCours={enCours} libelle={libelle} />
        </form>
      </CarteFormulaire>
    </div>
  )
}

/** Grille de champs à deux colonnes, comme les formulaires parcauto (`row` / `col-md-6`). */
export function GrilleChamps({ children }) {
  return <div className="grid gap-4 sm:grid-cols-2">{children}</div>
}
