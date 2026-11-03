import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, MapPin, Pencil, UserRound } from 'lucide-react'
import Input from '../../../components/ui/Input'
import Select from '../../../components/ui/Select'
import SelecteurMultiple from '../../../components/ui/SelecteurMultiple'
import { fetchApplications } from '../plateformeApi'
import { GrilleFormulaire, OngletsFormulaire } from '../../../components/ui/CarteFormulaire'
import {
  creerSocietePlateforme, fetchSocietePlateforme, fetchStructureSocietePlateforme, modifierSocietePlateforme,
} from '../adminApi'
import { PageFormulaire, flash, messageApi } from './kit'

/**
 * Nouvelle société / modification — la fiche société de dbmasterbacou.US_SOCIETE, sur sa
 * propre page, en trois onglets : Identité, Coordonnées, Représentant légal.
 *
 * Les champs affichés et leur longueur maximale viennent du serveur (structure réelle de
 * US_SOCIETE) : une colonne absente de la base n'est pas proposée, et une saisie ne peut
 * pas dépasser la largeur de sa colonne.
 *
 * La société est créée dans le registre de la plateforme puis reprise aussitôt dans
 * ECOPRIM. Le sigle ne se modifie plus ensuite : établissements, comptes et applications
 * y sont rattachés. Les colonnes techniques (base de la société, compteurs, provisionnement)
 * ne sont jamais saisies ici.
 */

/** Les champs, par onglet, dans l'ordre d'affichage. */
const ONGLETS = [
  {
    cle: 'identite', libelle: 'Identité', icon: Building2,
    champs: [
      ['nom', 'Raison Sociale *'],
      ['forme_juridique', 'Forme juridique', { placeholder: 'SARL, SA, SAS…' }],
      ['regime_fiscal', 'Régime fiscal'],
      ['activite', 'Activité principale'],
      ['activite_secondaire', 'Activité secondaire'],
      ['capital', 'Capital (FCFA)', { type: 'number', min: 0, step: 1 }],
      ['codification', 'Codification'],
    ],
  },
  {
    cle: 'coordonnees', libelle: 'Coordonnées', icon: MapPin,
    champs: [
      ['adresse', 'Adresse'],
      ['adresse2', 'Adresse (suite)'],
      ['complement', 'Complément'],
      ['code_postal', 'Boîte postale / Code postal'],
      ['ville', 'Ville'],
      ['lieu', 'Lieu'],
      ['pays', 'Pays'],
      ['telephone', 'Téléphone', { type: 'tel' }],
      ['fax', 'Fax', { type: 'tel' }],
      ['email', 'Email', { type: 'email', placeholder: 'exemple@societe.ci' }],
      ['site', 'Site web', { placeholder: 'www.societe.ci' }],
    ],
  },
  {
    cle: 'representant', libelle: 'Représentant légal', icon: UserRound,
    champs: [
      ['civilite_representant', 'Civilité'],
      ['representant', 'Nom et prénoms'],
      ['fonction_representant', 'Fonction', { placeholder: 'Gérant, Directeur général…' }],
      ['tel_representant', 'Téléphone', { type: 'tel' }],
      ['cel_representant', 'Cellulaire', { type: 'tel' }],
    ],
  },
]

const CIVILITES = ['M.', 'Mme', 'Mlle', 'Dr', 'Me']

export default function SocieteFormPage() {
  const { code } = useParams()
  const edition = Boolean(code)

  const { data: structure, error: erreurStructure } = useQuery({
    queryKey: ['societes', 'structure'], queryFn: fetchStructureSocietePlateforme, staleTime: Infinity, retry: false,
  })
  const { data: societe, error: erreurFiche } = useQuery({
    queryKey: ['societes', 'fiche', code], queryFn: () => fetchSocietePlateforme(code), enabled: edition, retry: false,
  })

  const erreurChargement = erreurStructure || erreurFiche
  if (erreurChargement || !structure || (edition && !societe)) {
    return (
      <PageFormulaire
        icon={edition ? Pencil : Building2} titre={edition ? 'Modifier la Société' : 'Ajouter une nouvelle société'}
        retour="/admin/societes" onSubmit={() => {}} enCours largeur="max-w-5xl"
      >
        <p className="py-6 text-center text-muted">
          {erreurChargement ? messageApi(erreurChargement, 'Société introuvable.') : 'Chargement…'}
        </p>
      </PageFormulaire>
    )
  }

  const initial = { code: '' }
  for (const [cle, c] of Object.entries(structure.champs)) {
    const v = societe?.[cle]
    initial[cle] = c.nature === 'booleen' ? Boolean(v) : (v ?? '')
  }
  if (societe) initial.code = societe.code

  return <Formulaire key={code ?? 'nouvelle'} code={code} structure={structure} initial={initial} />
}

/** Le formulaire lui-même, initialisé une fois pour toutes avec les valeurs chargées. */
function Formulaire({ code, structure, initial }) {
  const edition = Boolean(code)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [form, setForm] = useState(initial)
  // Une société doit être affectée à au moins une application : choisie dès la création.
  const [applications, setApplications] = useState([])
  const { data: catalogue } = useQuery({
    queryKey: ['plateforme', 'applications'], queryFn: fetchApplications, enabled: !edition, retry: false,
  })
  const optionsApplications = (catalogue?.applications ?? [])
    .map((a) => ({ valeur: a.code, libelle: a.libelle || a.code }))
  const [onglet, setOnglet] = useState('identite')
  const [erreurs, setErreurs] = useState({})
  const [erreur, setErreur] = useState(null)

  const { champs } = structure
  const disponible = (k) => Boolean(champs[k])

  /** Ce qui part au serveur : les seuls champs que la base accepte, nettoyés. */
  const charge = (v) => {
    const corps = {}
    for (const [cle, c] of Object.entries(champs)) {
      if (c.nature === 'booleen') corps[cle] = Boolean(v[cle])
      else if (c.nature === 'entier') corps[cle] = v[cle] === '' || v[cle] === null ? null : Number(v[cle])
      else corps[cle] = String(v[cle] ?? '').trim() || null
    }
    if (!edition) {
      corps.code = v.code.trim()
      corps.applications = applications
    }
    return corps
  }

  const enregistrer = useMutation({
    mutationFn: (v) => (edition ? modifierSocietePlateforme(code, charge(v)) : creerSocietePlateforme(charge(v))),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['societes'] })
      qc.invalidateQueries({ queryKey: ['console-contexte'] })
      flash.succes(edition ? `La société ${form.nom} a été mise à jour.` : `La société ${form.nom} a été créée.`)
      navigate('/admin/societes')
    },
    onError: (e) => {
      const errs = e?.response?.data?.errors ?? {}
      setErreurs(errs)
      setErreur(messageApi(e))
      // Une erreur dans un autre onglet : on y bascule, pour qu'elle se voie.
      const fautif = ONGLETS.find((o) => o.champs.some(([k]) => errs[k])) ?? (errs.code ? ONGLETS[0] : null)
      if (fautif) setOnglet(fautif.cle)
    },
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const rendre = ([k, label, props = {}]) => {
    if (!disponible(k)) return null
    const c = champs[k]
    const id = `societe-${k}`
    const err = erreurs[k]?.[0]

    if (c.nature === 'booleen') {
      return (
        <Select key={k} id={id} label={label} value={form[k] ? '1' : '0'} onChange={(e) => champ(k, e.target.value === '1')} error={err}>
          <option value="1">Oui</option>
          <option value="0">Non</option>
        </Select>
      )
    }
    if (k === 'civilite_representant') {
      return (
        <Select key={k} id={id} label={label} value={form[k] ?? ''} onChange={(e) => champ(k, e.target.value)} error={err}>
          <option value="">—</option>
          {CIVILITES.filter((x) => !c.largeur || x.length <= c.largeur).map((x) => <option key={x} value={x}>{x}</option>)}
          {form[k] && !CIVILITES.includes(form[k]) && <option value={form[k]}>{form[k]}</option>}
        </Select>
      )
    }
    return (
      <Input
        key={k} id={id} label={label} value={form[k] ?? ''} onChange={(e) => champ(k, e.target.value)} error={err}
        maxLength={c.largeur ?? undefined} {...props}
      />
    )
  }

  const soumettre = () => {
    const manque = [
      !edition && !form.code.trim() && 'code',
      !String(form.nom ?? '').trim() && 'nom',
      !edition && applications.length === 0 && 'applications',
    ].filter(Boolean)
    if (manque.length) {
      setErreurs(Object.fromEntries(manque.map((k) => [k, ['Ce champ est obligatoire.']])))
      setErreur('Renseignez les champs marqués d’un astérisque.')
      setOnglet('identite')
      return
    }
    setErreur(null)
    setErreurs({})
    enregistrer.mutate(form)
  }

  const onglets = ONGLETS
    .filter((o) => o.champs.some(([k]) => disponible(k)))
    .map((o) => ({
      ...o, erreur: o.champs.some(([k]) => erreurs[k]) || (o.cle === 'identite' && Boolean(erreurs.code || erreurs.applications)),
    }))
  const courant = ONGLETS.find((o) => o.cle === onglet) ?? ONGLETS[0]

  return (
    <PageFormulaire
      icon={edition ? Pencil : Building2}
      titre={edition ? 'Modifier la Société' : 'Ajouter une nouvelle société'}
      retour="/admin/societes"
      onSubmit={soumettre}
      enCours={enregistrer.isPending}
      erreur={erreur}
      libelle={edition ? 'Mettre à jour' : 'Enregistrer'}
      largeur="max-w-5xl"
    >
      <OngletsFormulaire onglets={onglets} actif={courant.cle} onChanger={setOnglet} />

      <GrilleFormulaire colonnes={3}>
        {courant.cle === 'identite' && (
          <Input
            id="societe-code" label="Sigle *" value={form.code}
            onChange={(e) => champ('code', e.target.value)} error={erreurs.code?.[0]}
            maxLength={structure.code.largeur} autoFocus={!edition}
            placeholder="Entrez le sigle de la société"
            disabled={edition} title={edition ? 'Le sigle d’une société ne se modifie pas.' : undefined}
          />
        )}
        {courant.cle === 'identite' && !edition && (
          <SelecteurMultiple
            id="societe-applications" label="Applications *" options={optionsApplications}
            selection={applications} onChange={setApplications}
            placeholder="— Choisir au moins une application —"
            error={erreurs.applications?.[0] ?? Object.entries(erreurs).find(([k]) => k.startsWith('applications.'))?.[1]?.[0]}
          />
        )}
        {courant.champs.map(rendre)}
      </GrilleFormulaire>
    </PageFormulaire>
  )
}
