import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, X, School, Mail, Phone, User, MapPin, ListChecks, Landmark, Briefcase } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import {
  fetchEtablissements,
  createEtablissement,
  updateEtablissement,
  deleteEtablissement,
  activerEtablissement,
  desactiverEtablissement,
  fetchAllSocietes,
} from './adminApi'

const TYPES = ['maternelle', 'primaire', 'college', 'lycee', 'groupe_scolaire', 'secondaire']

const schema = z.object({
  societe_id: z.string().min(1, 'La société est requise'),
  code: z.string().min(1, 'Le code est requis').max(30),
  nom: z.string().min(1, 'Le nom est requis').max(150),
  type: z.string().optional(),
  adresse: z.string().optional(),
  telephone: z.string().optional(),
  email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  nom_responsable: z.string().optional(),
  prenom_responsable: z.string().optional(),
  fonction_responsable: z.string().optional(),
  contact_responsable: z.string().optional(),
  email_directeur: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  sous_prefecture: z.string().optional(),
  circonscription: z.string().optional(),
  code_iep: z.string().optional(),
  intitule_iep: z.string().optional(),
  code_dren: z.string().optional(),
  intitule_dren: z.string().optional(),
})

const EMPTY = {
  societe_id: '', code: '', nom: '', type: '', adresse: '', telephone: '', email: '',
  nom_responsable: '', prenom_responsable: '', fonction_responsable: '', contact_responsable: '', email_directeur: '',
  sous_prefecture: '', circonscription: '', code_iep: '', intitule_iep: '', code_dren: '', intitule_dren: '',
}

export default function EtablissementListPage() {
  const [editing, setEditing] = useState(null)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['admin', 'etablissements', 1], queryFn: () => fetchEtablissements(1) })
  const { data: societes } = useQuery({ queryKey: ['admin', 'societes', 'all'], queryFn: fetchAllSocietes })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm({ resolver: zodResolver(schema) })

  const openCreate = () => {
    reset(EMPTY)
    setEditing({})
  }

  const openEdit = (etab) => {
    reset({
      ...EMPTY,
      ...Object.fromEntries(Object.keys(EMPTY).map((key) => [key, etab[key] ?? ''])),
      societe_id: String(etab.societe_id),
    })
    setEditing(etab)
  }

  const saveMutation = useMutation({
    mutationFn: (values) => (editing?.id ? updateEtablissement(editing.id, values) : createEtablissement(values)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'etablissements'] })
      setEditing(null)
    },
    onError: (error) => alert(error.response?.data?.message ?? 'Enregistrement impossible.'),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteEtablissement,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'etablissements'] }),
    onError: (error) => alert(error.response?.data?.message ?? 'Suppression impossible.'),
  })

  const activerMutation = useMutation({
    mutationFn: (etab) => activerEtablissement(etab.id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'etablissements'] }),
    onError: (error) => alert(error.response?.data?.message ?? "Activation impossible."),
  })

  const desactiverMutation = useMutation({
    mutationFn: (etab) => desactiverEtablissement(etab.id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'etablissements'] }),
    onError: (error) => alert(error.response?.data?.message ?? "Désactivation impossible."),
  })

  const statutColor = {
    actif: 'bg-green-50 text-green-700',
    brouillon: 'bg-slate-100 text-slate-500',
    inactif: 'bg-red-50 text-red-600',
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Établissements</h1>
        <Button onClick={openCreate}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter un établissement
          </span>
        </Button>
      </div>

      {editing && (
        <form
          onSubmit={handleSubmit((values) => saveMutation.mutate(values))}
          className="mb-6 space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">
              {editing.id ? "Modifier l'établissement" : 'Nouvel établissement'}
            </h2>
            <button type="button" onClick={() => setEditing(null)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>

          <FormSection icon={School} title="Informations générales">
            <div className="grid grid-cols-3 gap-4">
              <Select icon={ListChecks} label="Société" error={errors.societe_id?.message} {...register('societe_id')}>
                <option value="">— Sélectionner —</option>
                {societes?.map((societe) => (
                  <option key={societe.id} value={societe.id}>
                    {societe.nom}
                  </option>
                ))}
              </Select>
              <Input icon={School} label="Code" error={errors.code?.message} {...register('code')} />
              <Input icon={School} label="Nom" error={errors.nom?.message} {...register('nom')} />
            </div>
            <Select icon={ListChecks} label="Type" error={errors.type?.message} {...register('type')}>
              <option value="">— Non renseigné —</option>
              {TYPES.map((type) => (
                <option key={type} value={type}>
                  {type.replace('_', ' ')}
                </option>
              ))}
            </Select>
          </FormSection>

          <FormSection icon={Phone} title="Coordonnées">
            <Input icon={MapPin} label="Adresse" error={errors.adresse?.message} {...register('adresse')} />
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Phone} label="Téléphone" error={errors.telephone?.message} {...register('telephone')} />
              <Input icon={Mail} label="Email" error={errors.email?.message} {...register('email')} />
            </div>
          </FormSection>

          <FormSection icon={User} title="Responsable pédagogique">
            <div className="grid grid-cols-3 gap-4">
              <Input icon={User} label="Nom" error={errors.nom_responsable?.message} {...register('nom_responsable')} />
              <Input icon={User} label="Prénom" error={errors.prenom_responsable?.message} {...register('prenom_responsable')} />
              <Input icon={Briefcase} label="Fonction" error={errors.fonction_responsable?.message} {...register('fonction_responsable')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Phone} label="Contact" error={errors.contact_responsable?.message} {...register('contact_responsable')} />
              <Input icon={Mail} label="Email du directeur" error={errors.email_directeur?.message} {...register('email_directeur')} />
            </div>
          </FormSection>

          <FormSection icon={Landmark} title="Rattachement administratif" description="DREN / IEP (Côte d'Ivoire)">
            <div className="grid grid-cols-2 gap-4">
              <Input icon={MapPin} label="Sous-préfecture" error={errors.sous_prefecture?.message} {...register('sous_prefecture')} />
              <Input icon={MapPin} label="Circonscription" error={errors.circonscription?.message} {...register('circonscription')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Landmark} label="Code IEP" error={errors.code_iep?.message} {...register('code_iep')} />
              <Input icon={Landmark} label="Intitulé IEP" error={errors.intitule_iep?.message} {...register('intitule_iep')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Landmark} label="Code DREN" error={errors.code_dren?.message} {...register('code_dren')} />
              <Input icon={Landmark} label="Intitulé DREN" error={errors.intitule_dren?.message} {...register('intitule_dren')} />
            </div>
          </FormSection>

          <div className="flex justify-end">
            <Button type="submit" disabled={saveMutation.isPending}>
              Enregistrer
            </Button>
          </div>
        </form>
      )}

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Société</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {data?.data?.map((etab) => (
              <tr key={etab.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{etab.code}</td>
                <td className="px-4 py-3 text-slate-600">{etab.nom}</td>
                <td className="px-4 py-3 text-slate-600">{etab.societe?.nom ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{etab.type?.replace('_', ' ') ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statutColor[etab.statut]}`}>
                    {etab.statut}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    {etab.statut !== 'actif' && (
                      <button
                        onClick={() => activerMutation.mutate(etab)}
                        className="rounded px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-50"
                      >
                        Activer
                      </button>
                    )}
                    {etab.statut === 'actif' && (
                      <button
                        onClick={() => desactiverMutation.mutate(etab)}
                        className="rounded px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100"
                      >
                        Désactiver
                      </button>
                    )}
                    <button
                      onClick={() => openEdit(etab)}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </button>
                    <button
                      onClick={() => confirm(`Supprimer l'établissement ${etab.nom} ?`) && deleteMutation.mutate(etab.id)}
                      className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
