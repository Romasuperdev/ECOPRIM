import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, PlusCircle } from 'lucide-react'
import Input from '../../../components/ui/Input'
import { creerApplication, fetchApplication, modifierApplication } from '../plateformeApi'
import { PageFormulaire, flash, messageApi } from './kit'

/**
 * Nouvelle application / modification — sur sa propre page, comme
 * console/applications/create et edit dans parcauto : Code Application et Intitulé.
 * Le code, repris par les comptes et les sociétés, ne se modifie pas.
 */
export default function ApplicationFormPage() {
  const { code } = useParams()
  const edition = Boolean(code)

  const { data: application, error: erreurChargement } = useQuery({
    queryKey: ['plateforme-applications', 'fiche', code], queryFn: () => fetchApplication(code), enabled: edition, retry: false,
  })

  if (!edition) return <Formulaire initial={{ code: '', intitule: '' }} />

  if (erreurChargement || !application) {
    return (
      <PageFormulaire icon={Pencil} titre="Modifier Application" retour="/admin/applications" onSubmit={() => {}} enCours largeur="max-w-2xl">
        <p className="py-6 text-center text-muted">
          {erreurChargement ? messageApi(erreurChargement, 'Application introuvable.') : 'Chargement…'}
        </p>
      </PageFormulaire>
    )
  }

  return <Formulaire key={application.code} code={code} initial={{ code: application.code, intitule: application.libelle ?? '' }} />
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
    mutationFn: (v) => (edition
      ? modifierApplication(code, { intitule: v.intitule.trim() })
      : creerApplication({ code: v.code.trim(), intitule: v.intitule.trim() })),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['plateforme-applications'] })
      qc.invalidateQueries({ queryKey: ['societes-applications'] })
      flash.succes(edition ? 'Application mise à jour avec succès !' : 'Application créée avec succès !')
      navigate('/admin/applications')
    },
    onError: (e) => {
      setErreurs(e?.response?.data?.errors ?? {})
      setErreur(messageApi(e))
    },
  })

  const titre = edition ? 'Modifier Application' : 'Nouvelle Application'
  const icone = edition ? Pencil : PlusCircle

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const soumettre = () => {
    if (!form.code.trim() || !form.intitule.trim()) {
      setErreur('Renseignez le code et l’intitulé.')
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
      retour="/admin/applications"
      onSubmit={soumettre}
      enCours={enregistrer.isPending}
      erreur={erreur}
      libelle={edition ? 'Mettre à jour' : 'Créer'}
      largeur="max-w-2xl"
    >
      <Input
        id="application-code" label="Code Application *" value={form.code} maxLength={50}
        disabled={edition} autoFocus={!edition}
        onChange={(e) => champ('code', e.target.value)} error={erreurs.code?.[0]}
      />
      <Input
        id="application-intitule" label="Intitulé *" value={form.intitule} maxLength={50} autoFocus={edition}
        onChange={(e) => champ('intitule', e.target.value)} error={erreurs.intitule?.[0]}
      />
    </PageFormulaire>
  )
}
