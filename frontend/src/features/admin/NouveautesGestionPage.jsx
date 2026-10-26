import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, Plus, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageErreur from '../../components/ui/MessageErreur'
import MessageRefus from '../../components/ui/MessageRefus'
import VersionCarte from '../nouveautes/VersionCarte'
import { TYPES_ELEMENT } from '../nouveautes/typesNouveautes'
import {
  CLES_NOUVEAUTES, enregistrerVersion, fetchNouveautesGestion, supprimerVersion,
} from '../nouveautes/nouveautesApi'
import { Actions, BoutonEntete, BoutonModifier, BoutonSupprimer, CarteConsole } from './console/kit'

const aujourdhui = () => new Date().toISOString().slice(0, 10)
const ELEMENT_VIDE = { titre: '', description: '', type: 'nouveaute' }

// Clé locale d'un élément dans le formulaire : les éléments neufs n'ont pas encore d'id,
// et l'index ne convient pas — retirer une ligne décalerait toutes les suivantes.
let compteur = 0
const cleLocale = () => `nouveau-${++compteur}`

const versionVide = () => ({
  numero: '', titre: '', description: '', date_sortie: aujourdhui(), active: true,
  elements: [{ ...ELEMENT_VIDE, _cle: cleLocale() }],
})

/**
 * Console — gestion des Nouveautés (Super Admin).
 *
 * Une version et ses éléments s'éditent ensemble : à l'enregistrement, le serveur aligne
 * les éléments sur la liste envoyée (conserve, modifie, ajoute, retire). Une version
 * inactive reste ici ; les utilisateurs, eux, ne la voient pas.
 */
export default function NouveautesGestionPage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data, isLoading, error } = useQuery({
    queryKey: CLES_NOUVEAUTES.gestion, queryFn: fetchNouveautesGestion, retry: false,
  })

  const rafraichir = () => {
    qc.invalidateQueries({ queryKey: CLES_NOUVEAUTES.gestion })
    qc.invalidateQueries({ queryKey: CLES_NOUVEAUTES.liste })
  }
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: enregistrerVersion,
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Enregistrement impossible.'] }),
  })
  const supprimer = useMutation({
    mutationFn: supprimerVersion,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const champElement = (i, k, v) =>
    setForm((f) => ({ ...f, elements: f.elements.map((e, j) => (j === i ? { ...e, [k]: v } : e)) }))
  const ajouterElement = () =>
    setForm((f) => ({ ...f, elements: [...f.elements, { ...ELEMENT_VIDE, _cle: cleLocale() }] }))
  const retirerElement = (i) =>
    setForm((f) => ({ ...f, elements: f.elements.filter((_, j) => j !== i) }))

  const ouvrir = (v) => {
    setErreurs({})
    setForm({
      ...v,
      description: v.description ?? '',
      elements: v.elements.map((e) => ({ ...e, description: e.description ?? '', _cle: `id-${e.id}` })),
    })
  }

  const valider = () => enregistrer.mutate({
    id: form.id,
    numero: form.numero,
    titre: form.titre,
    description: form.description || null,
    date_sortie: form.date_sortie,
    active: form.active,
    elements: form.elements.map(({ id, titre, description, type }) => ({
      ...(id ? { id } : {}), titre, description: description || null, type,
    })),
  })

  const incomplet = form && (
    !form.numero.trim() || !form.titre.trim() || !form.date_sortie || form.elements.some((e) => !e.titre.trim())
  )

  return (
    <CarteConsole
      icon={Bell}
      titre="Gestion des nouveautés"
      action={(
        <BoutonEntete icon={Plus} onClick={() => { setErreurs({}); setForm(versionVide()) }}>
          Nouvelle version
        </BoutonEntete>
      )}
    >
      <p className="mb-4 text-sm font-medium text-muted">
        Les notes de version annoncées aux utilisateurs. Une version active s’affiche
        une fois à chacun, jusqu’à ce qu’il indique en avoir pris connaissance.
      </p>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}
      {!isLoading && !data && <MessageErreur erreur={error} permission="Console générale (Super Admin)" />}
      {data?.length === 0 && (
        <p className="card rounded-2xl px-5 py-8 text-center text-sm font-medium text-muted">
          Aucune version publiée pour l’instant.
        </p>
      )}

      <div className="space-y-4">
        {data?.map((v) => (
          <VersionCarte
            key={v.id}
            version={v}
            pastilleEtat
            actions={(
              <Actions>
                <BoutonModifier titre="Modifier la version" onClick={() => ouvrir(v)} />
                <BoutonSupprimer
                  titre="Supprimer la version"
                  confirmation={`Supprimer la version ${v.numero} ? Ses éléments et les lectures enregistrées disparaîtront avec elle.`}
                  onConfirme={() => supprimer.mutate(v.id)}
                />
              </Actions>
            )}
            pied={(
              <p className="mt-3 text-xs font-medium text-muted">
                Prise en compte par {v.lectures} compte{v.lectures > 1 ? 's' : ''}.
              </p>
            )}
          />
        ))}
      </div>

      {form && (
        <ModaleFormulaire
          large
          titre={form.id ? `Modifier la version ${form.numero}` : 'Nouvelle version'}
          onFermer={fermer}
          onValider={valider}
          enCours={enregistrer.isPending}
          valideDesactive={incomplet}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <Input id="v-numero" label="Numéro" placeholder="1.4.0" maxLength={50} value={form.numero}
                   error={erreurs.numero?.[0]} onChange={(e) => champ('numero', e.target.value)} />
            <Input id="v-date" label="Date de sortie" type="date" value={form.date_sortie}
                   error={erreurs.date_sortie?.[0]} onChange={(e) => champ('date_sortie', e.target.value)} />
          </div>
          <Input id="v-titre" label="Titre" maxLength={200} value={form.titre}
                 error={erreurs.titre?.[0]} onChange={(e) => champ('titre', e.target.value)} />
          <Textarea id="v-description" label="Description" rows={3} value={form.description}
                    error={erreurs.description?.[0]} onChange={(e) => champ('description', e.target.value)} />
          <label className="flex items-center gap-2 text-sm font-bold text-heading">
            <input type="checkbox" checked={form.active} onChange={(e) => champ('active', e.target.checked)} />
            Publiée (visible des utilisateurs)
          </label>

          <fieldset className="space-y-3">
            <legend className="mb-2 text-sm font-bold text-heading">Éléments</legend>
            {form.elements.length === 0 && <p className="text-sm text-muted">Aucun élément.</p>}
            {form.elements.map((e, i) => (
              <div key={e._cle} className="surface-2 space-y-2 rounded-xl p-3">
                <div className="flex items-start gap-2">
                  <div className="w-40 shrink-0">
                    <Select id={`el-type-${e._cle}`} aria-label="Type" value={e.type}
                            error={erreurs[`elements.${i}.type`]?.[0]}
                            onChange={(ev) => champElement(i, 'type', ev.target.value)}>
                      {Object.entries(TYPES_ELEMENT).map(([code, t]) => <option key={code} value={code}>{t.libelle}</option>)}
                    </Select>
                  </div>
                  <div className="min-w-0 flex-1">
                    <Input id={`el-titre-${e._cle}`} aria-label="Titre de l’élément" placeholder="Titre" maxLength={200}
                           value={e.titre} error={erreurs[`elements.${i}.titre`]?.[0]}
                           onChange={(ev) => champElement(i, 'titre', ev.target.value)} />
                  </div>
                  <button type="button" title="Retirer cet élément" onClick={() => retirerElement(i)}
                          className="mt-2 shrink-0 rounded-lg p-1.5 text-muted hover:bg-black/5">
                    <X size={16} />
                  </button>
                </div>
                <Textarea id={`el-desc-${e._cle}`} aria-label="Description de l’élément" rows={2}
                          placeholder="Précision (facultative)" value={e.description}
                          onChange={(ev) => champElement(i, 'description', ev.target.value)} />
              </div>
            ))}
            <Button type="button" variant="outline" className="!px-3 !py-1" onClick={ajouterElement}>
              <Plus size={14} /> Ajouter un élément
            </Button>
          </fieldset>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </CarteConsole>
  )
}
