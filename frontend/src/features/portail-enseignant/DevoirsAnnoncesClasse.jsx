import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Camera, CheckCircle2, FileText, ImagePlus, Megaphone, NotebookPen, Plus, X } from 'lucide-react'
import { apiVieClasse, fetchMesMatieres } from './portailEnseignantApi'
import { CarteAnnonce, CarteDevoir } from '../vie-classe/Cartes'

/*
 * Onglet « Devoirs & annonces » de la classe, dans l'espace Enseignant.
 *
 *   Devoirs  : matière, texte, date de remise, et jusqu'à 4 photos — le tableau ou une
 *              fiche, prises avec l'appareil du téléphone ou choisies dans la galerie.
 *   Annonces : un message aux parents de la classe, avec une photo facultative.
 *
 * Chaque publication prévient les parents de la classe.
 */
const PIECES_MAX = 4

function demain() {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function aujourdhui() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function messageErreur(erreur) {
  const errs = erreur?.response?.data?.errors
  if (errs) return Object.values(errs).flat()[0]
  if (erreur && !erreur.response) return 'Pas de réseau : réessayez quand la connexion revient.'
  return erreur?.response?.data?.message ?? null
}

export default function DevoirsAnnoncesClasse({ classe }) {
  const [vue, setVue] = useState('devoirs')

  return (
    <div>
      <div className="mb-4 flex rounded-full p-1" style={{ background: 'var(--surface-2)' }} role="tablist">
        {[['devoirs', 'Devoirs', NotebookPen], ['annonces', 'Annonces', Megaphone]].map(([cle, libelle, Icone]) => (
          <button
            key={cle} type="button" role="tab" aria-selected={vue === cle} onClick={() => setVue(cle)}
            className="flex flex-1 items-center justify-center gap-1.5 rounded-full px-3 py-2 text-sm font-bold"
            style={vue === cle ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' } : { color: 'var(--muted-text)' }}
          >
            <Icone size={16} /> {libelle}
          </button>
        ))}
      </div>
      {vue === 'devoirs' ? <Devoirs classe={classe} /> : <Annonces classe={classe} />}
    </div>
  )
}

// --- Devoirs ---------------------------------------------------------------------------

function Devoirs({ classe }) {
  const qc = useQueryClient()
  const [creation, setCreation] = useState(false)
  const [publie, setPublie] = useState(false)
  const { data, isLoading } = useQuery({ queryKey: ['devoirs-classe', classe], queryFn: () => apiVieClasse.devoirs(classe) })
  const retirer = useMutation({
    mutationFn: (d) => apiVieClasse.supprimerDevoir(d.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['devoirs-classe', classe] }),
  })
  const devoirs = data?.devoirs ?? []

  return (
    <div>
      {creation ? (
        <FormulaireDevoir
          classe={classe}
          onFermer={() => setCreation(false)}
          onPublie={() => {
            setCreation(false)
            setPublie(true)
            qc.invalidateQueries({ queryKey: ['devoirs-classe', classe] })
            qc.invalidateQueries({ queryKey: ['mes-devoirs-accueil'] })
          }}
        />
      ) : (
        <BoutonPlus onClick={() => { setPublie(false); setCreation(true) }}>Donner un devoir</BoutonPlus>
      )}
      {publie && <Succes>Devoir publié : les parents de la classe sont prévenus.</Succes>}

      {isLoading && <p className="text-muted">Chargement…</p>}
      {!isLoading && devoirs.length === 0 && <p className="card px-4 py-10 text-center text-sm text-muted">Aucun devoir donné à cette classe.</p>}
      <ul className="space-y-3">
        {devoirs.map((d) => (
          <CarteDevoir
            key={d.id} devoir={d} charger={apiVieClasse.piece}
            onRetirer={(x) => { if (window.confirm(`Retirer le devoir « ${x.titre} » ?`)) retirer.mutate(x) }}
          />
        ))}
      </ul>
    </div>
  )
}

function FormulaireDevoir({ classe, onFermer, onPublie }) {
  const { data: matieres } = useQuery({ queryKey: ['mes-matieres', classe], queryFn: () => fetchMesMatieres(classe) })
  const [v, setV] = useState({ matiere: '', titre: '', consigne: '', date_remise: demain() })
  const [photos, setPhotos] = useState([])
  const champ = (cle) => (e) => setV((p) => ({ ...p, [cle]: e.target.value }))
  const matiere = v.matiere || matieres?.[0]?.code || ''

  const publier = useMutation({
    mutationFn: () => {
      const f = new FormData()
      f.append('classe', classe)
      f.append('matiere', matiere)
      f.append('titre', v.titre.trim())
      if (v.consigne.trim()) f.append('consigne', v.consigne.trim())
      f.append('date_remise', v.date_remise)
      photos.forEach((p) => f.append('pieces[]', p))
      return apiVieClasse.creerDevoir(f)
    },
    onSuccess: onPublie,
  })

  return (
    <form className="card mb-4 space-y-3 p-4" onSubmit={(e) => { e.preventDefault(); publier.mutate() }}>
      <EnTeteFormulaire titre="Nouveau devoir" onFermer={onFermer} />
      <select className="field" value={matiere} onChange={champ('matiere')} aria-label="Matière" required>
        {(matieres ?? []).map((m) => <option key={m.code} value={m.code}>{m.libelle}</option>)}
      </select>
      <input className="field" placeholder="Devoir (ex. Exercices 4 et 5 page 32)" value={v.titre} onChange={champ('titre')} maxLength={150} required />
      <textarea className="field min-h-[80px]" placeholder="Consigne (facultatif)" value={v.consigne} onChange={champ('consigne')} maxLength={5000} />
      <label className="block text-sm font-bold text-heading">
        Pour le
        <input type="date" className="field mt-1" value={v.date_remise} min={aujourdhui()} onChange={champ('date_remise')} required />
      </label>
      <ChoixPhotos photos={photos} setPhotos={setPhotos} max={PIECES_MAX} />
      {publier.isError && <Erreur>{messageErreur(publier.error)}</Erreur>}
      <BoutonEnvoyer disabled={publier.isPending || !v.titre.trim() || !matiere}>
        {publier.isPending ? 'Envoi…' : 'Publier le devoir'}
      </BoutonEnvoyer>
    </form>
  )
}

// --- Annonces ----------------------------------------------------------------------------

function Annonces({ classe }) {
  const qc = useQueryClient()
  const [creation, setCreation] = useState(false)
  const [prevenus, setPrevenus] = useState(null)
  const { data, isLoading } = useQuery({ queryKey: ['annonces-classe', classe], queryFn: () => apiVieClasse.annonces(classe) })
  const retirer = useMutation({
    mutationFn: (a) => apiVieClasse.supprimerAnnonce(a.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['annonces-classe', classe] }),
  })
  const annonces = data?.annonces ?? []

  return (
    <div>
      {creation ? (
        <FormulaireAnnonce
          classe={classe}
          onFermer={() => setCreation(false)}
          onPubliee={(a) => {
            setCreation(false)
            setPrevenus(a.parents_prevenus)
            qc.invalidateQueries({ queryKey: ['annonces-classe', classe] })
          }}
        />
      ) : (
        <BoutonPlus onClick={() => { setPrevenus(null); setCreation(true) }}>Nouvelle annonce aux parents</BoutonPlus>
      )}
      {prevenus !== null && (
        <Succes>Annonce envoyée : {prevenus} parent{prevenus > 1 ? 's' : ''} prévenu{prevenus > 1 ? 's' : ''}.</Succes>
      )}

      {isLoading && <p className="text-muted">Chargement…</p>}
      {!isLoading && annonces.length === 0 && <p className="card px-4 py-10 text-center text-sm text-muted">Aucune annonce envoyée à cette classe.</p>}
      <ul className="space-y-3">
        {annonces.map((a) => (
          <CarteAnnonce
            key={a.id} annonce={a} chargerPhoto={apiVieClasse.photoAnnonce}
            onRetirer={(x) => { if (window.confirm(`Retirer l’annonce « ${x.titre} » ?`)) retirer.mutate(x) }}
          />
        ))}
      </ul>
    </div>
  )
}

function FormulaireAnnonce({ classe, onFermer, onPubliee }) {
  const [v, setV] = useState({ titre: '', message: '' })
  const [photos, setPhotos] = useState([])
  const champ = (cle) => (e) => setV((p) => ({ ...p, [cle]: e.target.value }))

  const envoyer = useMutation({
    mutationFn: () => {
      const f = new FormData()
      f.append('classe', classe)
      f.append('titre', v.titre.trim())
      if (v.message.trim()) f.append('message', v.message.trim())
      if (photos[0]) f.append('photo', photos[0])
      return apiVieClasse.creerAnnonce(f)
    },
    onSuccess: onPubliee,
  })

  return (
    <form className="card mb-4 space-y-3 p-4" onSubmit={(e) => { e.preventDefault(); envoyer.mutate() }}>
      <EnTeteFormulaire titre="Annonce aux parents de la classe" onFermer={onFermer} />
      <input className="field" placeholder="Titre (ex. Sortie scolaire demain)" value={v.titre} onChange={champ('titre')} maxLength={150} required />
      <textarea className="field min-h-[90px]" placeholder="Message (ex. Prévoir une casquette et une gourde.)" value={v.message} onChange={champ('message')} maxLength={2000} />
      <ChoixPhotos photos={photos} setPhotos={setPhotos} max={1} />
      {envoyer.isError && <Erreur>{messageErreur(envoyer.error)}</Erreur>}
      <BoutonEnvoyer disabled={envoyer.isPending || !v.titre.trim()}>
        {envoyer.isPending ? 'Envoi…' : 'Envoyer aux parents'}
      </BoutonEnvoyer>
    </form>
  )
}

// --- Petits éléments ---------------------------------------------------------------------

/** Photos jointes : prendre une photo (appareil arrière) ou choisir dans la galerie. */
function ChoixPhotos({ photos, setPhotos, max }) {
  const apercus = useMemo(() => photos.map((f) => ({ fichier: f, url: f.type.startsWith('image/') ? URL.createObjectURL(f) : null })), [photos])
  useEffect(() => () => apercus.forEach((a) => a.url && URL.revokeObjectURL(a.url)), [apercus])

  const ajouter = (e) => {
    const nouveaux = Array.from(e.target.files ?? [])
    setPhotos((p) => [...p, ...nouveaux].slice(0, max))
    e.target.value = ''
  }
  const plein = photos.length >= max

  return (
    <div>
      {!plein && (
        <div className="grid grid-cols-2 gap-2">
          <label className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed px-3 py-3 text-sm font-bold"
            style={{ borderColor: 'var(--border-strong)', color: 'var(--heading)' }}>
            <Camera size={18} /> Photo
            <input type="file" accept="image/*" capture="environment" className="sr-only" onChange={ajouter} />
          </label>
          <label className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed px-3 py-3 text-sm font-bold"
            style={{ borderColor: 'var(--border-strong)', color: 'var(--heading)' }}>
            <ImagePlus size={18} /> Galerie
            <input type="file" accept="image/*,application/pdf" multiple={max > 1} className="sr-only" onChange={ajouter} />
          </label>
        </div>
      )}
      {apercus.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-2">
          {apercus.map((a, i) => (
            <div key={`${a.fichier.name}-${i}`} className="relative">
              {a.url
                ? <img src={a.url} alt="" className="h-20 w-20 rounded-xl object-cover" />
                : <span className="flex h-20 w-20 items-center justify-center rounded-xl" style={{ background: 'var(--surface-2)' }}><FileText size={22} /></span>}
              <button
                type="button" onClick={() => setPhotos((p) => p.filter((_, j) => j !== i))}
                className="absolute -right-1.5 -top-1.5 rounded-full p-1 shadow" style={{ background: 'var(--card)' }}
                aria-label="Retirer cette photo"
              >
                <X size={13} />
              </button>
            </div>
          ))}
        </div>
      )}
      <p className="mt-1 text-xs text-muted">{max > 1 ? `Jusqu’à ${max} photos ou PDF, 8 Mo chacun.` : 'Une photo ou un PDF, 8 Mo au plus (facultatif).'}</p>
    </div>
  )
}

function BoutonPlus({ onClick, children }) {
  return (
    <button
      type="button" onClick={onClick}
      className="mb-4 flex w-full items-center justify-center gap-2 rounded-2xl px-4 py-3.5 text-base font-extrabold shadow-md transition active:scale-[.99]"
      style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
    >
      <Plus size={20} /> {children}
    </button>
  )
}

function EnTeteFormulaire({ titre, onFermer }) {
  return (
    <div className="flex items-center justify-between">
      <h3 className="font-bold text-heading">{titre}</h3>
      <button type="button" onClick={onFermer} aria-label="Fermer" className="p-1 text-muted"><X size={18} /></button>
    </div>
  )
}

function BoutonEnvoyer({ disabled, children }) {
  return (
    <button type="submit" disabled={disabled}
      className="w-full rounded-xl px-4 py-3 text-sm font-extrabold disabled:opacity-50"
      style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}>
      {children}
    </button>
  )
}

function Succes({ children }) {
  return (
    <p className="mb-3 flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold"
      style={{ background: 'var(--success-bg)', borderColor: 'var(--success-border)', color: 'var(--success-text)' }}>
      <CheckCircle2 size={17} /> {children}
    </p>
  )
}

function Erreur({ children }) {
  return <p className="text-sm" style={{ color: 'var(--danger-text)' }}>{children}</p>
}
