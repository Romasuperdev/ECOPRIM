import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building, Pencil } from 'lucide-react'
import Input from '../../../components/ui/Input'
import Select from '../../../components/ui/Select'
import { createEtablissement, fetchEtablissementParCode, updateEtablissement } from '../adminApi'
import { fetchConsoleContexte } from '../consoleContexteApi'
import { GrilleChamps, PageFormulaire, flash, messageApi } from './kit'

const VIDE = {
  code: '', intitule: '', type: '', adresse: '', ville: '', pays: 'Côte d’Ivoire',
  telephone: '', email: '', site_web: '', societe_code: '',
}

const TYPES = [['PRIVE', 'Privé'], ['PUBLIC', 'Public'], ['CONFESSIONNEL', 'Confessionnel']]

/**
 * Nouvel établissement / modification — sur sa propre page, comme
 * console/etablissements/create et edit dans parcauto.
 *
 * Trois cas, que l'API distingue seule :
 *   — un code inconnu crée l'établissement ;
 *   — un établissement déjà présent dans la base métier mais pas encore dans ECOPRIM
 *     est REPRIS (il est alors modifiable et désactivable) ;
 *   — un établissement repris est modifié.
 */
export default function EtablissementFormPage() {
  const { code } = useParams()
  const { data: etab, error } = useQuery({
    queryKey: ['etablissements', 'fiche', code], queryFn: () => fetchEtablissementParCode(code), enabled: Boolean(code), retry: false,
  })

  if (!code) return <Formulaire key="nouveau" initial={{ ...VIDE }} />

  if (error || !etab) {
    return (
      <PageFormulaire icon={Pencil} titre="Modifier l’Établissement" retour="/admin/etablissements" onSubmit={() => {}} enCours>
        <p className="py-6 text-center text-muted">
          {error ? messageApi(error, 'Établissement introuvable.') : 'Chargement…'}
        </p>
      </PageFormulaire>
    )
  }

  // Le formulaire part de la fiche chargée ; ensuite il appartient à la saisie.
  const initial = { ...VIDE }
  for (const k of Object.keys(VIDE)) if (etab[k] != null) initial[k] = etab[k]

  return <Formulaire key={code} etab={etab} initial={initial} />
}

function Formulaire({ etab, initial }) {
  const edition = Boolean(etab)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [form, setForm] = useState(initial)
  const [erreurs, setErreurs] = useState({})
  const [erreur, setErreur] = useState(null)
  // Les sociétés viennent du contexte de la console : /societes est réservé au Super
  // Admin, un Admin Société n'y trouverait rien.
  const { data: contexte } = useQuery({ queryKey: ['console-contexte'], queryFn: fetchConsoleContexte, retry: false })
  const societes = contexte?.societes ?? []

  // Un établissement pas encore repris n'a pas d'identifiant ECOPRIM : on le reprend.
  const reprise = edition && !etab.id

  const enregistrer = useMutation({
    mutationFn: (v) => (edition && etab?.id ? updateEtablissement(etab.id, v) : createEtablissement(v)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['etablissements'] })
      flash.succes(
        !edition ? `L’établissement ${form.intitule} a été créé.`
          : reprise ? `L’établissement ${form.intitule} a été repris.`
            : `L’établissement ${form.intitule} a été modifié.`,
      )
      navigate('/admin/etablissements')
    },
    onError: (e) => {
      setErreurs(e?.response?.data?.errors ?? {})
      setErreur(messageApi(e))
    },
  })

  const titre = !edition ? 'Nouvel Établissement' : reprise ? 'Reprendre l’Établissement' : 'Modifier l’Établissement'
  const icone = edition ? Pencil : Building

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const saisie = (k, label, props = {}) => (
    <Input label={label} value={form[k] ?? ''} onChange={(e) => champ(k, e.target.value)} error={erreurs[k]?.[0]} {...props} />
  )
  const incomplet = ['code', 'intitule', 'societe_code', 'adresse', 'pays'].some((k) => !String(form[k] ?? '').trim())

  return (
    <PageFormulaire
      icon={icone}
      titre={titre}
      retour="/admin/etablissements"
      onSubmit={() => { if (!incomplet) { setErreur(null); enregistrer.mutate(form) } else setErreur('Renseignez les champs marqués d’un astérisque.') }}
      enCours={enregistrer.isPending}
      erreur={erreur}
      libelle={edition ? 'Enregistrer' : 'Créer'}
    >
      {reprise && (
        <p className="rounded-lg border px-3 py-2 text-sm" style={{ background: 'var(--info-bg)', borderColor: 'var(--info-border)', color: 'var(--info-text)' }}>
          Cet établissement n’est pas encore géré dans ECOPRIM : l’enregistrer le reprend, sans
          modifier sa fiche d’origine. Il pourra ensuite être modifié ou désactivé.
        </p>
      )}
      <GrilleChamps>
        {saisie('code', 'Code *', { disabled: edition })}
        {saisie('intitule', 'Nom *')}
        <Select label="Société *" value={form.societe_code ?? ''} onChange={(e) => champ('societe_code', e.target.value)} error={erreurs.societe_code?.[0]}>
          <option value="">— Choisir une société —</option>
          {societes.map((s) => <option key={s.code} value={s.code}>{s.code} - {s.nom}</option>)}
          {form.societe_code && !societes.some((s) => s.code === form.societe_code) && (
            <option value={form.societe_code}>{form.societe_code}</option>
          )}
        </Select>
        <Select label="Type" value={form.type ?? ''} onChange={(e) => champ('type', e.target.value)} error={erreurs.type?.[0]}>
          <option value="">— Non précisé —</option>
          {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </Select>
        {saisie('adresse', 'Adresse *')}
        {saisie('ville', 'Ville')}
        {saisie('pays', 'Pays *')}
        {saisie('telephone', 'Téléphone')}
        {saisie('email', 'Email', { type: 'email' })}
        {saisie('site_web', 'Site web')}
      </GrilleChamps>
    </PageFormulaire>
  )
}
