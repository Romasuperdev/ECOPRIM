import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import { GrilleFormulaire, OngletsFormulaire, RecapErreurs } from '../../components/ui/CarteFormulaire'
import { fetchContexte } from '../contexte/contexteApi'
import { Eye, ImagePlus, Lock, Printer, User, UserPlus } from 'lucide-react'
import {
  createInscription,
  fetchInscriptions,
  fetchReferentiels,
  fetchPhotoBlob,
  televerserPhoto,
  updateInscription,
} from './inscriptionsApi'
import { imprimerFicheEleve, imprimerListeClasse } from '../impressions/impressionApi'
import ModaleAcces from '../../components/ui/ModaleAcces'

const MOUVEMENTS = [
  { cle: 'inscription', label: 'Inscription' },
  { cle: 'reinscription', label: 'Réinscription' },
  { cle: 'transfert_entrant', label: 'Transfert entrant' },
  { cle: 'transfert_sortant', label: 'Transfert sortant' },
]

// Formulaire à onglets librement accessibles, dans l'ordre demandé. Chaque onglet liste
// ses champs : une erreur (locale ou du serveur) sur l'un d'eux marque l'onglet, et
// l'enregistrement y ramène l'utilisateur. `annee`, refusée par le serveur pour une année
// clôturée, relève de la Scolarité.
const ONGLETS = [
  { libelle: 'Identité de l’élève', champs: ['matricule', 'nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite'] },
  { libelle: 'Coordonnées', champs: ['adresse', 'ville', 'commune', 'quartier', 'telephone', 'email'] },
  { libelle: 'Scolarité', champs: ['mouvement', 'niveau_code', 'classe_code', 'redoublant', 'date_inscription', 'etab_origine', 'niveau_origine', 'annee'] },
  { libelle: 'Père / Tuteur', champs: ['pere_nom', 'pere_prenom', 'pere_profession', 'pere_telephone', 'pere_email'] },
  { libelle: 'Mère', champs: ['mere_nom', 'mere_prenom', 'mere_profession', 'mere_telephone', 'mere_email'] },
  { libelle: 'Photo', champs: ['photo'] },
]
const ONGLET_PHOTO = ONGLETS.length - 1

// Champs bloquants : l'enregistrement ne part pas s'ils sont vides, quel que soit
// l'onglet affiché. L'année n'y figure plus : elle n'est plus saisie, elle vient de l'en-tête.
const REQUIS = ['matricule', 'nom', 'prenom', 'mouvement']

/** Index de l'onglet portant le champ `cle` (« eleves.0 » relève de « eleves »), -1 sinon. */
const ongletDe = (cle) => ONGLETS.findIndex((o) => o.champs.includes(String(cle).split('.')[0]))

/** Premier onglet concerné par ces erreurs, -1 si aucune ne vise un champ connu. */
const premierOngletEnErreur = (err) => {
  const indices = Object.keys(err).map(ongletDe).filter((i) => i >= 0)
  return indices.length ? Math.min(...indices) : -1
}

const VIDE = {
  mouvement: 'inscription',
  matricule: '', nom: '', prenom: '', sexe: '', date_naissance: '', lieu_naissance: '', nationalite: '',
  adresse: '', ville: '', commune: '', quartier: '', telephone: '', email: '',
  // Pas d'`annee` : à la création, c'est le serveur qui applique l'année de travail.
  niveau_code: '', classe_code: '', redoublant: '',
  etab_origine: '', niveau_origine: '', date_inscription: '',
  pere_nom: '', pere_prenom: '', pere_profession: '', pere_telephone: '', pere_email: '',
  mere_nom: '', mere_prenom: '', mere_profession: '', mere_telephone: '', mere_email: '',
}

export default function InscriptionListPage() {
  const [page, setPage] = useState(1)
  const [filtres, setFiltres] = useState({ q: '', classe: '' })
  const [form, setForm] = useState(null)
  const [onglet, setOnglet] = useState(0)
  const [photo, setPhoto] = useState(null)        // fichier choisi, pas encore envoyé
  const [apercu, setApercu] = useState(null)      // URL locale d'aperçu
  const [erreurPhoto, setErreurPhoto] = useState(null)
  const [photoBlob, setPhotoBlob] = useState(null) // { id, url } de la photo déjà en base
  const [erreurs, setErreurs] = useState({})
  // Identifiants du parent, affichés une seule fois après l'enregistrement.
  const [accesParent, setAccesParent] = useState(null)
  const qc = useQueryClient()

  const { data: contexte } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })
  // L'année de travail se choisit une fois pour toutes dans l'en-tête : la page la
  // suit, elle ne la redemande pas.
  const anneeEffective = contexte?.annee ?? ''

  // La limite vient du serveur : c'est lui qui la fait respecter. L'écran s'en sert
  // seulement pour éviter un envoi voué au refus, et pour l'annoncer justement — la valeur
  // était écrite en dur ici, et elle a fini par ne plus dire la même chose que le serveur.
  const photoMaxKo = contexte?.photo_max_ko ?? 4096
  const photoMaxMo = Math.round(photoMaxKo / 1024)

  // `annee` n'est volontairement PAS transmise. Sans elle, le serveur applique
  // ContexteScolaire, qui filtre sur le libellé ET sur le code — ECONOMAT stockant
  // tantôt l'un tantôt l'autre. Le filtre explicite, lui, faisait une égalité stricte
  // sur le seul libellé et pouvait donc masquer des élèves bien inscrits.
  const { data, isLoading } = useQuery({
    queryKey: ['inscriptions', page, filtres, anneeEffective],
    queryFn: () => fetchInscriptions({
      page,
      q: filtres.q || undefined,
      classe: filtres.classe || undefined,
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
          // L'élève est bien enregistré : on ne perd pas la saisie pour une photo. On
          // emporte son identifiant, sans quoi un second essai le RECRÉERAIT et buterait
          // sur « matricule déjà attribué » — l'utilisateur croirait avoir tout perdu.
          throw Object.assign(new Error('photo'), {
            eleveId: eleve.id,
            photoSeulement: e?.response?.data?.errors?.photo?.[0]
              ?? e?.response?.data?.message
              ?? 'La photo n’a pas pu être enregistrée.',
          })
        }
      }
      return eleve
    },
    onSuccess: (eleve) => {
      invalider(); setForm(null); setErreurs({}); reinitPhoto()
      if (eleve?.acces_parent) setAccesParent(eleve.acces_parent)
    },
    onError: (e) => {
      if (e?.photoSeulement) {
        invalider()
        setErreurPhoto(e.photoSeulement)
        setOnglet(ONGLET_PHOTO)
        // Le dossier existe désormais : le formulaire passe en modification, pour qu'un
        // nouvel essai corrige la photo au lieu de recréer l'élève.
        if (e.eleveId) setForm((f) => (f ? { ...f, id: e.eleveId, _anneeInitiale: f._anneeInitiale ?? '' } : f))
        return
      }
      const err = e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }
      setErreurs(err)
      // Si un champ d'un autre onglet est refusé, on y ramène l'utilisateur.
      const fautif = premierOngletEnErreur(err)
      if (fautif >= 0) setOnglet(fautif)
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
  const photoExistante = form?.id && photoBlob?.id === form.id ? photoBlob.url : null

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
    if (fichier.size > photoMaxKo * 1024) {
      setErreurPhoto(`L’image ne doit pas dépasser ${photoMaxMo} Mo.`)
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
  // Un dossier existant est verrouillé par SA propre année ; un dossier neuf l'est par
  // l'année de travail, puisque c'est dans celle-là qu'il sera créé.
  const dossierVerrouille = Boolean(form)
    && (form.id ? anneeCloturee(form._anneeInitiale) : filtreVerrouille)

  // Onglets identiques en création et en modification.
  const visible = (index) => onglet === index
  const ongletEnErreur = (index) =>
    Object.keys(erreurs).some((cle) => ongletDe(cle) === index) || (index === ONGLET_PHOTO && Boolean(erreurPhoto))

  /**
   * Enregistrement : un clic explicite sur « Enregistrer », et rien d'autre.
   *
   * Tout le dossier est contrôlé d'un coup, quel que soit l'onglet affiché : un champ
   * requis vide sur un autre onglet y ramène l'utilisateur, l'onglet étant marqué.
   * La touche Entrée n'enregistre pas (`entreeValide={false}`) : un Entrée réflexe, juste
   * après avoir choisi la photo par exemple, aurait envoyé un dossier inachevé.
   */
  const soumettre = () => {
    if (dossierVerrouille || enregistrer.isPending) return
    const manquants = REQUIS.filter((c) => !String(form?.[c] ?? '').trim())
    if (manquants.length) {
      const errLocales = Object.fromEntries(manquants.map((c) => [c, ['Ce champ est requis.']]))
      setErreurs(errLocales)
      setOnglet(premierOngletEnErreur(errLocales))
      return
    }
    setErreurs({})
    enregistrer.mutate(form)
  }

  // Les classes se restreignent au niveau choisi quand l'info existe.
  const niveauxFiltres = ref?.niveaux ?? []
  const classesFiltrees = (ref?.classes ?? []).filter((c) => !form?.niveau_code || c.niveau_code === form.niveau_code)

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Élèves et inscriptions</h1>
          {/* Le compte dit d'emblée combien d'élèves sont inscrits pour l'année de
              travail — c'est la première chose qu'on vient vérifier sur cet écran. */}
          <p className="mt-1 text-sm font-medium text-muted">
            {isLoading
              ? 'Chargement…'
              : `${data?.total ?? 0} élève${(data?.total ?? 0) > 1 ? 's' : ''} inscrit${
                  (data?.total ?? 0) > 1 ? 's' : ''
                }${anneeEffective ? ` en ${anneeEffective}` : ''}`}
          </p>
          <p className="mt-0.5 text-sm text-muted">
            Consultez leur fiche, ou ouvrez un dossier d’inscription, de réinscription ou de
            transfert.
          </p>
        </div>
        <div className="flex items-center gap-2">
        <Button
          variant="outline"
          onClick={() => imprimerListeClasse({ classe: filtres.classe, annee: anneeEffective })}
          title="Liste nominative de la classe filtrée"
        >
          <Printer size={16} className="mr-1.5 inline" />
          Imprimer la liste
        </Button>
        <Button
          disabled={filtreVerrouille}
          title={filtreVerrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => {
            setErreurs({})
            setOnglet(0)
            reinitPhoto()
            setForm({ ...VIDE })
          }}
        >
          + Nouvelle inscription
        </Button>
        </div>
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
        <div className="min-w-[150px]">
          <Select label="Classe" value={filtres.classe}
                  onChange={(e) => { setPage(1); setFiltres((f) => ({ ...f, classe: e.target.value })) }}>
            <option value="">Toutes</option>
            {ref?.classes?.map((c) => <option key={c.id ?? c.code} value={c.code}>{c.libelle ?? c.code}</option>)}
          </Select>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Matricule</th>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Né(e) le</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={5} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-muted">Aucune inscription.</td></tr>
            )}
            {data?.data?.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs text-slate-700">{e.matricule ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-800">{e.nom} {e.prenom}</div>
                  <div className="text-xs text-muted">{e.sexe ?? ''}</div>
                </td>
                <td className="px-4 py-3 text-slate-600">{e.classe_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{e.date_naissance ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex items-center justify-end gap-2">
                  {/* Reprise de l'ancienne page « Élèves » : la fiche détaillée
                      (bulletin, documents, assiduité) reste un écran à part. */}
                  <Link
                    to={`/eleves/${e.id}`}
                    title="Ouvrir la fiche de l'élève"
                    className="inline-flex rounded-full p-2 text-muted hover:bg-slate-100 hover:text-primary-700"
                  >
                    <Eye size={16} />
                  </Link>
                  <Button variant="outline" className="!px-3 !py-1"
                          title="Imprimer la fiche de l'élève"
                          onClick={() => imprimerFicheEleve(e.id)}>
                    <Printer size={14} />
                  </Button>
                  <Button variant="outline" className="!px-3 !py-1"
                          onClick={() => {
                            setErreurs({})
                            setOnglet(0)
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
                  </div>
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

      <p className="mt-3 text-xs text-muted">
        Les élèves sont partagés avec ECONOMAT : ils peuvent être créés et modifiés ici, jamais
        supprimés. Le volet financier (scolarité, versements, remises) reste géré dans ECONOMAT.
      </p>

      {form && (
        <ModaleFormulaire
          titrePage="Élèves et inscriptions"
          titre={form.id ? `Modifier — ${form.nom} ${form.prenom}` : 'Nouvelle inscription'}
          icon={UserPlus}
          large
          onFermer={() => setForm(null)}
          onValider={soumettre}
          entreeValide={false}
          enCours={enregistrer.isPending}
          valideDesactive={dossierVerrouille}
          titreValider={dossierVerrouille ? 'Année clôturée : consultation seule.' : undefined}
          // « Enregistrer avec la photo » se lisait « enregistrer la photo », comme si le
          // reste de la saisie ne partait pas. Le bouton nomme donc ce qu'il enregistre :
          // le dossier entier, photo comprise.
          libelleValider={form.id ? 'Enregistrer les modifications' : 'Enregistrer l’inscription'}
        >
          {dossierVerrouille && (
            <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <Lock size={16} className="mt-0.5 shrink-0" />
              <span>
                Année clôturée : ce dossier est en consultation seule. L’enregistrement est
                désactivé.
              </span>
            </div>
          )}

          <RecapErreurs erreurs={[...Object.values(erreurs).flat(), erreurPhoto]} />

          <OngletsFormulaire
            onglets={ONGLETS.map((o, i) => ({ cle: i, libelle: o.libelle, erreur: ongletEnErreur(i) }))}
            actif={onglet}
            onChanger={setOnglet}
          />

          <div className={visible(0) ? '' : 'hidden'}>
            {!form.id && (
              <p className="mb-3 text-xs text-muted">
                Le matricule identifie l’élève de façon unique. Pour un élève déjà connu de
                l’établissement, saisissez son matricule et choisissez « Réinscription » à
                l’onglet Scolarité : son dossier sera mis à jour, pas dupliqué.
              </p>
            )}
            <GrilleFormulaire>
              <Input label="Matricule *" value={form.matricule} onChange={(e) => champ('matricule', e.target.value)} error={err('matricule')} />
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
            </GrilleFormulaire>
          </div>

          <div className={visible(1) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Input label="Adresse" value={form.adresse} onChange={(e) => champ('adresse', e.target.value)} error={err('adresse')} />
              <Input label="Ville" value={form.ville} onChange={(e) => champ('ville', e.target.value)} error={err('ville')} />
              <Input label="Commune" value={form.commune} onChange={(e) => champ('commune', e.target.value)} error={err('commune')} />
              <Input label="Quartier" value={form.quartier} onChange={(e) => champ('quartier', e.target.value)} error={err('quartier')} />
              <Input label="Téléphone" value={form.telephone} onChange={(e) => champ('telephone', e.target.value)} error={err('telephone')} />
              <Input label="Email" value={form.email} onChange={(e) => champ('email', e.target.value)} error={err('email')} />
            </GrilleFormulaire>
          </div>

          <div className={visible(2) ? '' : 'hidden'}>
            {/* L'année ne se saisit plus. On la rappelle quand même : savoir dans
                quelle année on inscrit ne doit pas obliger à regarder l'en-tête. */}
            <p className="mb-3 text-sm font-medium text-muted">
              {form.id
                ? <>Dossier de l’année <strong className="text-heading">{form._anneeInitiale || '—'}</strong>.</>
                : <>Inscription dans l’année <strong className="text-heading">{anneeEffective || '—'}</strong>, celle choisie dans l’en-tête.</>}
            </p>
            <GrilleFormulaire>
              <Select label="Type de mouvement *" value={form.mouvement}
                      onChange={(e) => champ('mouvement', e.target.value)} error={err('mouvement')}>
                {MOUVEMENTS.map((m) => <option key={m.cle} value={m.cle}>{m.label}</option>)}
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
            </GrilleFormulaire>
            {(form.mouvement === 'transfert_entrant' || form.mouvement === 'transfert_sortant') && (
              <div className="mt-4"><GrilleFormulaire colonnes={2}>
                <Input label="Établissement d’origine / d’accueil" value={form.etab_origine} onChange={(e) => champ('etab_origine', e.target.value)} error={err('etab_origine')} />
                <Input label="Niveau d’origine" value={form.niveau_origine} onChange={(e) => champ('niveau_origine', e.target.value)} error={err('niveau_origine')} />
              </GrilleFormulaire></div>
            )}
          </div>

          <div className={visible(3) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Input label="Nom" value={form.pere_nom} onChange={(e) => champ('pere_nom', e.target.value)} error={err('pere_nom')} />
              <Input label="Prénom(s)" value={form.pere_prenom} onChange={(e) => champ('pere_prenom', e.target.value)} error={err('pere_prenom')} />
              <Input label="Profession" value={form.pere_profession} onChange={(e) => champ('pere_profession', e.target.value)} error={err('pere_profession')} />
              <Input label="Téléphone" value={form.pere_telephone} onChange={(e) => champ('pere_telephone', e.target.value)} error={err('pere_telephone')} />
              <Input label="Email" value={form.pere_email} onChange={(e) => champ('pere_email', e.target.value)} error={err('pere_email')} />
            </GrilleFormulaire>
          </div>

          <div className={visible(4) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Input label="Nom" value={form.mere_nom} onChange={(e) => champ('mere_nom', e.target.value)} error={err('mere_nom')} />
              <Input label="Prénom(s)" value={form.mere_prenom} onChange={(e) => champ('mere_prenom', e.target.value)} error={err('mere_prenom')} />
              <Input label="Profession" value={form.mere_profession} onChange={(e) => champ('mere_profession', e.target.value)} error={err('mere_profession')} />
              <Input label="Téléphone" value={form.mere_telephone} onChange={(e) => champ('mere_telephone', e.target.value)} error={err('mere_telephone')} />
              <Input label="Email" value={form.mere_email} onChange={(e) => champ('mere_email', e.target.value)} error={err('mere_email')} />
            </GrilleFormulaire>
          </div>

          <div className={visible(5) ? '' : 'hidden'}>
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

                <p className="mt-3 text-xs text-muted">
                  JPG, PNG ou WebP, {photoMaxMo} Mo maximum. La photo est enregistrée dans le dossier
                  partagé lu par ECONOMAT et nommée d’après le matricule de l’élève ; elle
                  remplace la précédente s’il en existait une.
                </p>
                <p className="mt-1 text-xs text-muted">
                  Elle part avec le dossier : c’est le bouton
                  <strong> {form.id ? 'Enregistrer les modifications' : 'Enregistrer l’inscription'} </strong>
                  ci-dessous qui enregistre tout, informations et photo comprises.
                </p>
              </div>
            </div>
          </div>
        </ModaleFormulaire>
      )}

      <ModaleAcces acces={accesParent} onFermer={() => setAccesParent(null)} />
    </div>
  )
}
