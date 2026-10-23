import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil } from 'lucide-react'
import Input from '../../../components/ui/Input'
import Select from '../../../components/ui/Select'
import { creerSocietePlateforme, fetchSocietePlateforme, modifierSocietePlateforme } from '../adminApi'
import { GrilleChamps, PageFormulaire, flash, messageApi } from './kit'

const VIDE = { code: '', nom: '', email: '', codification: false, capital: '' }

/**
 * Nouvelle société / modification — sur sa propre page, comme societes/create et edit
 * dans parcauto : Sigle, Raison sociale, Email à la création ; Codification et Capital
 * en plus à la modification.
 *
 * La société est créée dans le registre de la plateforme puis reprise aussitôt dans
 * ECOPRIM. Le sigle ne se modifie plus ensuite : établissements, comptes et applications
 * y sont rattachés.
 */
export default function SocieteFormPage() {
  const { code } = useParams()
  const edition = Boolean(code)

  const { data: societe, error: erreurChargement } = useQuery({
    queryKey: ['societes', 'fiche', code], queryFn: () => fetchSocietePlateforme(code), enabled: edition, retry: false,
  })

  if (!edition) return <Formulaire initial={VIDE} />

  if (erreurChargement || !societe) {
    return (
      <PageFormulaire icon={Pencil} titre="Modifier la Société" retour="/admin/societes" onSubmit={() => {}} enCours largeur="max-w-4xl">
        <p className="py-6 text-center text-muted">
          {erreurChargement ? messageApi(erreurChargement, 'Société introuvable.') : 'Chargement…'}
        </p>
      </PageFormulaire>
    )
  }

  return (
    <Formulaire
      key={societe.code}
      code={code}
      initial={{
        code: societe.code,
        nom: societe.nom ?? '',
        email: societe.email ?? '',
        codification: Boolean(societe.codification),
        capital: societe.capital ?? '',
      }}
    />
  )
}

/** Le formulaire lui-même, initialisé une fois pour toutes avec les valeurs chargées. */
function Formulaire({ code, initial }) {
  const edition = Boolean(code)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [form, setForm] = useState(initial)
  const [erreurs, setErreurs] = useState({})
  const [erreur, setErreur] = useState(null)

  const enregistrer = useMutation({
    mutationFn: (v) => {
      const commun = { nom: v.nom.trim(), email: v.email.trim() || null }
      return edition
        ? modifierSocietePlateforme(code, {
          ...commun, codification: v.codification, capital: v.capital === '' ? null : Number(v.capital),
        })
        : creerSocietePlateforme({ ...commun, code: v.code.trim() })
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['societes'] })
      qc.invalidateQueries({ queryKey: ['console-contexte'] })
      flash.succes(edition ? `La société ${form.nom} a été mise à jour.` : `La société ${form.nom} a été créée.`)
      navigate('/admin/societes')
    },
    onError: (e) => {
      setErreurs(e?.response?.data?.errors ?? {})
      setErreur(messageApi(e))
    },
  })

  const titre = edition ? 'Modifier la Société' : 'Ajouter une nouvelle société'
  const icone = edition ? Pencil : Building2

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const saisie = (k, label, props = {}) => (
    <Input id={`societe-${k}`} label={label} value={form[k] ?? ''} onChange={(e) => champ(k, e.target.value)} error={erreurs[k]?.[0]} {...props} />
  )
  const incomplet = ['code', 'nom'].some((k) => !String(form[k] ?? '').trim())

  const soumettre = () => {
    if (incomplet) {
      setErreur('Renseignez les champs marqués d’un astérisque.')
      return
    }
    setErreur(null)
    setErreurs({})
    enregistrer.mutate(form)
  }

  return (
    <PageFormulaire
      icon={icone}
      titre={titre}
      retour="/admin/societes"
      onSubmit={soumettre}
      enCours={enregistrer.isPending}
      erreur={erreur}
      libelle={edition ? 'Mettre à jour' : 'Enregistrer'}
      largeur={edition ? 'max-w-4xl' : 'max-w-2xl'}
    >
      {edition ? (
        <GrilleChamps>
          {saisie('code', 'Code Société *', { disabled: true, title: 'Le code d’une société ne se modifie pas.' })}
          {saisie('nom', 'Raison Sociale *', { maxLength: 50 })}
          {saisie('email', 'Email', { type: 'email', maxLength: 150 })}
          <Select
            id="societe-codification" label="Codification *"
            value={form.codification ? '1' : '0'}
            onChange={(e) => champ('codification', e.target.value === '1')}
            error={erreurs.codification?.[0]}
          >
            <option value="1">Oui</option>
            <option value="0">Non</option>
          </Select>
          {saisie('capital', 'Capital', { type: 'number', min: 0, step: 1 })}
        </GrilleChamps>
      ) : (
        <div className="space-y-4">
          {saisie('code', 'Sigle *', { placeholder: 'Entrez le sigle de la société', maxLength: 17, autoFocus: true })}
          {saisie('nom', 'Raison Sociale *', { placeholder: 'Entrez la raison sociale', maxLength: 50 })}
          {saisie('email', 'Email', { type: 'email', placeholder: 'exemple@societe.ci', maxLength: 150 })}
        </div>
      )}
    </PageFormulaire>
  )
}
