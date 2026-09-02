import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import StepIndicator from '../../components/ui/StepIndicator'
import { fetchContexte } from '../contexte/contexteApi'
import { ImagePlus, Lock, User } from 'lucide-react'
import {
  createInscription,
  fetchInscriptions,
  fetchReferentiels,
  fetchPhotoBlob,
  televerserPhoto,
  updateInscription,
} from './inscriptionsApi'

const MOUVEMENTS = [
  { cle: 'inscription', label: 'Inscription' },
  { cle: 'reinscription', label: 'Réinscription' },
  { cle: 'transfert_entrant', label: 'Transfert entrant' },
  { cle: 'transfert_sortant', label: 'Transfert sortant' },
]

// Assistant de saisie : une section par étape, dans l'ordre demandé.
const ETAPES = ['Identité de l’élève', 'Coordonnées', 'Scolarité', 'Père / Tuteur', 'Mère', 'Photo']

// Champs bloquants par étape : « Suivant » ne passe pas s'ils sont vides.
const REQUIS = [['nom', 'prenom'], [], ['mouvement', 'annee'], [], [], []]

const VIDE = {
  mouvement: 'inscription',
  matricule: '', nom: '', prenom: '', sexe: '', date_naissance: '', lieu_naissance: '', nationalite: '',
  adresse: '', ville: '', commune: '', quartier: '', telephone: '', email: '',
  annee: '', cycle_code: '', niveau_code: '', classe_code: '', redoublant: '',
  etab_origine: '', niveau_origine: '', date_inscription: '',
  pere_nom: '', pere_prenom: '', pere_profession: '', pere_telephone: '', pere_email: '',
  mere_nom: '', mere_prenom: '', mere_profession: '', mere_telephone: '', mere_email: '',
}

export default function InscriptionListPage() {
  const [page, setPage] = useState(1)
  // annee à null = on suit l'année choisie dans l'en-tête ; une chaîne = choix local.
  const [filtres, setFiltres] = useState({ q: '', annee: null, classe: '', mouvement: '' })
  const [form, setForm] = useState(null)
  const [etape, setEtape] = useState(0)
  const [photo, setPhoto] = useState(null)        // fichier choisi, pas encore envoyé
  const [apercu, setApercu] = useState(null)      // URL locale d'aperçu
  const [erreurPhoto, setErreurPhoto] = useState(null)
  const [photoBlob, setPhotoBlob] = useState(null) // { id, url } de la photo déjà en base
  const [erreurs, setErreurs] = useState({})
  const qc = useQueryClient()

  const { data: contexte } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })
  const anneeEffective = filtres.annee ?? contexte?.annee ?? ''

  const { data, isLoading } = useQuery({
    queryKey: ['inscriptions', page, { ...filtres, annee: anneeEffective }],
    queryFn: () => fetchInscriptions({
      page,
      q: filtres.q || undefined,
      annee: anneeEffective || undefined,
      classe: filtres.classe || undefined,
      mouvement: filtres.mouvement || undefined,
    }),
  })
  const { data: ref } = useQuery({ queryKey: ['referentiels'], queryFn: fetchReferentiels })

  const invalider = () => qc.invalidateQueries({ queryKey: ['inscriptions'] })

  const enregistrer = useMutation({
    // La photo ne peut partir qu'une fois l'élève enregistré : elle est nommée d'après lui.
    mutationFn: async (v) => {
      const eleve = v.id ? await updateInscription(v.id, v) : await createInscription(v)
      if (photo) {
        try {
          await televerserPhoto(eleve.id, photo)
        } catch (e) {
          // L'élève est bien enregistré : on ne perd pas la saisie pour une photo.
          throw Object.assign(new Error('photo'), {
            photoSeulement: e?.response?.data?.message ?? 'La photo n’a pas pu être enregistrée.',
          })
        }
      }
      return eleve
    },
    onSuccess: () => { invalider(); setForm(null); setErreurs({}); reinitPhoto() },
    onError: (e) => {
      if (e?.photoSeulement) {
        invalider()
        setErreurPhoto(e.photoSeulement)
        setEtape(ETAPES.length - 1)
        return
      }
      const err = e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }
      setErreurs(err)
      // Si un champ d'une étape précédente est refusé, on y ramène l'utilisateur.
      const fautive = REQUIS.findIndex((champs) => champs.some((c) => err[c]))
      if (fautive >= 0 && fautive !== etape) setEtape(fautive)
    },
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k) => erreurs[k]?.[0]

  // Photo déjà enregistrée : chargée en blob (le cookie de session passe par axios).
  useEffect(() => {
    if (!form?.id || !form?.photo) return undefined

    let url = null
    let annule = false
    fetchPhotoBlob(form.id)
      .then((blob) => {
        if (annule) return
        url = URL.createObjectURL(blob)
        setPhotoBlob({ id: form.id, url })
      })
      .catch(() => {})

    return () => {
      annule = true
      if (url) URL.revokeObjectURL(url)
    }
  }, [form?.id, form?.photo])

  // Dérivé : on n'affiche le blob que s'il correspond bien au dossier ouvert.
  const photoExistante = photoBlob?.id === form?.id ? photoBlob.url : null

  const reinitPhoto = () => {
    if (apercu) URL.revokeObjectURL(apercu)
    setPhoto(null)
    setApercu(null)
    setErreurPhoto(null)
  }

  const choisirPhoto = (fichier) => {
    setErreurPhoto(null)
    if (!fichier) return
    if (!fichier.type.startsWith('image/')) {
      setErreurPhoto('Le fichier doit être une image (JPG, PNG ou WebP).')
      return
    }
    if (fichier.size > 4 * 1024 * 1024) {
      setErreurPhoto('L’image ne doit pas dépasser 4 Mo.')
      return
    }
    if (apercu) URL.revokeObjectURL(apercu)
    setPhoto(fichier)
    setApercu(URL.createObjectURL(fichier))
  }

  // Une année clôturée est en consultation seule : ni ajout, ni modification.
  const anneeCloturee = (libelle) =>
    Boolean(libelle) && (ref?.annees ?? []).some((a) => a.libelle === libelle && a.cloturee)

  const filtreVerrouille = anneeCloturee(anneeEffective)
  const dossierVerrouille = Boolean(form) && (anneeCloturee(form.annee) || (form.id && anneeCloturee(form._anneeInitiale)))

  // Assistant identique en création et en modification.
  const enAssistant = Boolean(form)
  const visible = (index) => !enAssistant || etape === index
  const derniere = etape === ETAPES.length - 1

  /** Contrôle les champs bloquants de l'étape courante avant de passer à la suivante. */
  const suivant = () => {
    const manquants = REQUIS[etape].filter((c) => !String(form?.[c] ?? '').trim())
    if (manquants.length) {
      setErreurs(Object.fromEntries(manquants.map((c) => [c, ['Ce champ est requis.']])))
      return
    }
    setErreurs({})
    setEtape((e) => e + 1)
  }

  // Les niveaux et classes se restreignent au cycle / niveau choisi quand l'info existe.
  const niveauxFiltres = (ref?.niveaux ?? []).filter((n) => !form?.cycle_code || n.cycle_code === form.cycle_code)
  const classesFiltrees = (ref?.classes ?? []).filter((c) => !form?.niveau_code || c.niveau_code === form.niveau_code)

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Inscriptions</h1>
          <p className="mt-1 text-sm text-slate-500">
            Inscriptions, réinscriptions et transferts — saisie directe dans ECONOMAT.T_ETUDIANT.
          </p>
        </div>
        <Button
          disabled={filtreVerrouille}
          title={filtreVerrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => {
            setErreurs({})
            setEtape(0)
            reinitPhoto()
            setForm({ ...VIDE, annee: (!filtreVerrouille && anneeEffective) || ref?.annees?.find((a) => a.active && !a.cloturee)?.libelle || '' })
          }}
        >
          + Nouvelle inscription
        </Button>
      </div>

      {filtreVerrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>
            L’année <strong>{anneeEffective}</strong> est clôturée : les dossiers sont en
            consultation seule. Aucun ajout, aucune modification ni suppression n’y est possible.
          </span>
        </div>
      )}

      {/* Filtres */}
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[200px] flex-1">
          <Input label="Rechercher" placeholder="Nom, prénom ou matricule…" value={filtres.q}
                 onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, q: e.target.value })) }} />
        </div>
        <div className="min-w-[160px]">
          <Select label="Type de mouvement" value={filtres.mouvement}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, mouvement: e.target.value })) }}>
            <option value="">Tous</option>
            {MOUVEMENTS.map((m) => <option key={m.cle} value={m.cle}>{m.label}</option>)}
          </Select>
        </div>
        <div className="min-w-[150px]">
          <Select label="Année" value={anneeEffective}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, annee: e.target.value })) }}>
            <option value="">Toutes</option>
            {ref?.annees?.map((a) => <option key={a.id} value={a.libelle}>{a.libelle}</option>)}
          </Select>
        </div>
        <div className="min-w-[150px]">
          <Select label="Classe" value={filtres.classe}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, classe: e.target.value })) }}>
            <option value="">Toutes</option>
            {ref?.classes?.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
          </Select>
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Matricule</th>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Année</th>
              <th className="px-4 py-3 font-medium">Né(e) le</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucune inscription.</td></tr>
            )}
            {data?.data?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs text-slate-700">{e.matricule ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-800">{e.nom} {e.prenom}</div>
                  <div className="text-xs text-slate-400">{e.sexe ?? ''}</div>
                </td>
                <td className="px-4 py-3 text-slate-600">{e.classe_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.date_naissance ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Button variant="outline" className="!px-3 !py-1"
                          onClick={() => {
                            setErreurs({})
                            setEtape(0)
                            reinitPhoto()
                            setForm({
                              ...VIDE,
                              ...Object.fromEntries(Object.entries(e).map(([k, v]) => [k, v ?? ''])),
                              id: e.id,
                              _anneeInitiale: e.annee ?? '',
                            })
                          }}>
                    Éditer
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}

      <p className="mt-3 text-xs text-slate-400">
        Les élèves sont partagés avec ECONOMAT : ils peuvent être créés et modifiés ici, jamais
        supprimés. Le volet financier (scolarité, versements, remises) reste géré dans ECONOMAT.
      </p>

      {form && (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" onClick={() => setForm(null)}>
          <div className="my-8 w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl" onClick={(ev) => ev.stopPropagation()}>
            <h2 className="mb-1 text-lg font-bold text-slate-800">
              {form.id ? `Modifier — ${form.nom} ${form.prenom}` : 'Nouvelle inscription'}
            </h2>
            <p className="mb-5 text-xs text-slate-400">Formulaire alimenté par ECONOMAT.T_ETUDIANT.</p>

            {dossierVerrouille && (
              <div className="mb-5 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <Lock size={16} className="mt-0.5 shrink-0" />
                <span>
                  Année clôturée : ce dossier est en consultation seule. L’enregistrement est
                  désactivé.
                </span>
              </div>
            )}

            {enAssistant && (
              <StepIndicator etapes={ETAPES} etape={etape} onAller={(i) => { setErreurs({}); setEtape(i) }} />
            )}

            <form
              onSubmit={(ev) => {
                ev.preventDefault()
                // Entrée au clavier : on avance dans l'assistant au lieu d'enregistrer trop tôt.
                if (enAssistant && !derniere) return suivant()
                if (dossierVerrouille) return
                enregistrer.mutate(form)
              }}
              className="space-y-5"
            >

              <div className={visible(0) ? '' : 'hidden'}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Identité de l’élève</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Matricule" value={form.matricule} onChange={(e) => champ('matricule', e.target.value)} error={err('matricule')} />
                  <Input label="Nom *" value={form.nom} onChange={(e) => champ('nom', e.target.value)} error={err('nom')} />
                  <Input label="Prénom(s) *" value={form.prenom} onChange={(e) => champ('prenom', e.target.value)} error={err('prenom')} />
                  <Select label="Sexe" value={form.sexe} onChange={(e) => champ('sexe', e.target.value)} error={err('sexe')}>
                    <option value="">—</option>
                    <option value="M">Masculin</option>
                    <option value="F">Féminin</option>
                  </Select>
                  <Input label="Date de naissance" type="date" value={form.date_naissance} onChange={(e) => champ('date_naissance', e.target.value)} error={err('date_naissance')} />
                  <Input label="Lieu de naissance" value={form.lieu_naissance} onChange={(e) => champ('lieu_naissance', e.target.value)} error={err('lieu_naissance')} />
                  <Input label="Nationalité" value={form.nationalite} onChange={(e) => champ('nationalite', e.target.value)} error={err('nationalite')} />
                </div>
              </div>

              <div className={visible(1) ? '' : 'hidden'}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Coordonnées</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Adresse" value={form.adresse} onChange={(e) => champ('adresse', e.target.value)} error={err('adresse')} />
                  <Input label="Ville" value={form.ville} onChange={(e) => champ('ville', e.target.value)} error={err('ville')} />
                  <Input label="Commune" value={form.commune} onChange={(e) => champ('commune', e.target.value)} error={err('commune')} />
                  <Input label="Quartier" value={form.quartier} onChange={(e) => champ('quartier', e.target.value)} error={err('quartier')} />
                  <Input label="Téléphone" value={form.telephone} onChange={(e) => champ('telephone', e.target.value)} error={err('telephone')} />
                  <Input label="Email" value={form.email} onChange={(e) => champ('email', e.target.value)} error={err('email')} />
                </div>
              </div>

              <div className={visible(2) ? '' : 'hidden'}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Scolarité</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Select label="Type de mouvement *" value={form.mouvement}
                          onChange={(e) => champ('mouvement', e.target.value)} error={err('mouvement')}>
                    {MOUVEMENTS.map((m) => <option key={m.cle} value={m.cle}>{m.label}</option>)}
                  </Select>
                  <Select label="Année scolaire *" value={form.annee} onChange={(e) => champ('annee', e.target.value)} error={err('annee')}>
                    <option value="">— Choisir —</option>
                    {ref?.annees?.map((a) => (
                      <option key={a.id} value={a.libelle} disabled={a.cloturee && a.libelle !== form._anneeInitiale}>
                        {a.libelle}{a.cloturee ? ' (clôturée)' : ''}
                      </option>
                    ))}
                  </Select>
                  <Select label="Cycle" value={form.cycle_code} onChange={(e) => { champ('cycle_code', e.target.value); champ('niveau_code', ''); champ('classe_code', '') }} error={err('cycle_code')}>
                    <option value="">—</option>
                    {ref?.cycles?.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
                  </Select>
                  <Select label="Niveau" value={form.niveau_code} onChange={(e) => { champ('niveau_code', e.target.value); champ('classe_code', '') }} error={err('niveau_code')}>
                    <option value="">—</option>
                    {niveauxFiltres.map((n) => <option key={n.id ?? n.code} value={n.code}>{n.libelle ?? n.code}</option>)}
                  </Select>
                  <Select label="Classe" value={form.classe_code} onChange={(e) => champ('classe_code', e.target.value)} error={err('classe_code')}>
                    <option value="">—</option>
                    {classesFiltrees.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
                  </Select>
                  <Select label="Redoublant" value={form.redoublant} onChange={(e) => champ('redoublant', e.target.value)} error={err('redoublant')}>
                    <option value="">—</option>
                    <option value="OUI">Oui</option>
                    <option value="NON">Non</option>
                  </Select>
                  <Input label="Date d’inscription" type="date" value={form.date_inscription} onChange={(e) => champ('date_inscription', e.target.value)} error={err('date_inscription')} />
                </div>
                {(form.mouvement === 'transfert_entrant' || form.mouvement === 'transfert_sortant') && (
                  <div className="mt-3 grid grid-cols-2 gap-3">
                    <Input label="Établissement d’origine / d’accueil" value={form.etab_origine} onChange={(e) => champ('etab_origine', e.target.value)} error={err('etab_origine')} />
                    <Input label="Niveau d’origine" value={form.niveau_origine} onChange={(e) => champ('niveau_origine', e.target.value)} error={err('niveau_origine')} />
                  </div>
                )}
              </div>

              <div className={visible(3) ? '' : 'hidden'}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Père / Tuteur</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Nom" value={form.pere_nom} onChange={(e) => champ('pere_nom', e.target.value)} error={err('pere_nom')} />
                  <Input label="Prénom(s)" value={form.pere_prenom} onChange={(e) => champ('pere_prenom', e.target.value)} error={err('pere_prenom')} />
                  <Input label="Profession" value={form.pere_profession} onChange={(e) => champ('pere_profession', e.target.value)} error={err('pere_profession')} />
                  <Input label="Téléphone" value={form.pere_telephone} onChange={(e) => champ('pere_telephone', e.target.value)} error={err('pere_telephone')} />
                  <Input label="Email" value={form.pere_email} onChange={(e) => champ('pere_email', e.target.value)} error={err('pere_email')} />
                </div>
              </div>

              <div className={visible(4) ? '' : 'hidden'}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Mère</p>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                  <Input label="Nom" value={form.mere_nom} onChange={(e) => champ('mere_nom', e.target.value)} error={err('mere_nom')} />
                  <Input label="Prénom(s)" value={form.mere_prenom} onChange={(e) => champ('mere_prenom', e.target.value)} error={err('mere_prenom')} />
                  <Input label="Profession" value={form.mere_profession} onChange={(e) => champ('mere_profession', e.target.value)} error={err('mere_profession')} />
                  <Input label="Téléphone" value={form.mere_telephone} onChange={(e) => champ('mere_telephone', e.target.value)} error={err('mere_telephone')} />
                  <Input label="Email" value={form.mere_email} onChange={(e) => champ('mere_email', e.target.value)} error={err('mere_email')} />
                </div>

              <div className={visible(5) ? '' : 'hidden'}>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Photo</p>

                <div className="flex flex-wrap items-start gap-6">
                  {/* Aperçu : le fichier choisi, sinon la photo déjà en base */}
                  <div className="flex h-36 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                    {apercu ? (
                      <img src={apercu} alt="Aperçu de la photo" className="h-full w-full object-cover" />
                    ) : photoExistante ? (
                      <img src={photoExistante} alt={`Photo de ${form.nom}`} className="h-full w-full object-cover" />
                    ) : (
                      <User size={32} className="text-slate-300" />
                    )}
                  </div>

                  <div className="min-w-[240px] flex-1">
                    <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                      <ImagePlus size={16} />
                      {photo ? 'Changer la photo' : 'Choisir une photo'}
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        onChange={(e) => choisirPhoto(e.target.files?.[0])}
                      />
                    </label>

                    {photo && (
                      <div className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                        <span className="truncate">{photo.name}</span>
                        <button type="button" onClick={reinitPhoto} className="font-medium text-red-600 hover:underline">
                          Retirer
                        </button>
                      </div>
                    )}

                    {erreurPhoto && <p className="mt-2 text-sm text-red-600">{erreurPhoto}</p>}

                    <p className="mt-3 text-xs text-slate-400">
                      JPG, PNG ou WebP, 4 Mo maximum. La photo est enregistrée dans le dossier
                      partagé lu par ECONOMAT et nommée d’après le matricule de l’élève ; elle
                      remplace la précédente s’il en existait une.
                    </p>
                    {!form.id && (
                      <p className="mt-1 text-xs text-slate-400">
                        Elle sera envoyée juste après l’enregistrement de l’élève.
                      </p>
                    )}
                  </div>
                </div>
              </div>
              </div>

              {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

              <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-4">
                {enAssistant && etape > 0 ? (
                  <Button type="button" variant="outline" onClick={() => { setErreurs({}); setEtape((e) => e - 1) }}>
                    Précédent
                  </Button>
                ) : (
                  <Button type="button" variant="outline" onClick={() => setForm(null)}>Annuler</Button>
                )}

                <div className="flex items-center gap-2">
                  {enAssistant && (
                    <span className="text-xs text-slate-400">Étape {etape + 1} sur {ETAPES.length}</span>
                  )}
                  {enAssistant && !derniere ? (
                    <Button type="button" onClick={suivant}>Suivant</Button>
                  ) : (
                    <Button
                      type="submit"
                      disabled={enregistrer.isPending || dossierVerrouille}
                      title={dossierVerrouille ? 'Année clôturée : consultation seule.' : undefined}
                    >
                      {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer'}
                    </Button>
                  )}
                </div>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
