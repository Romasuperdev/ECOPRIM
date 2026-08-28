import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, X, Building2, Mail, Phone, User, MapPin, Scale, Landmark } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import FormSection from '../../components/ui/FormSection'
import { fetchSocietes, createSociete, updateSociete, deleteSociete, activerSociete, desactiverSociete } from './adminApi'

const schema = z.object({
  code: z.string().min(1, 'Le code est requis').max(30),
  nom: z.string().min(1, 'Le nom est requis').max(150),
  sigle: z.string().optional(),
  adresse: z.string().optional(),
  adresse_ligne2: z.string().optional(),
  code_postal: z.string().optional(),
  ville: z.string().optional(),
  pays: z.string().optional(),
  telephone: z.string().optional(),
  fax: z.string().optional(),
  email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  site_web: z.string().optional(),
  activite_principale: z.string().optional(),
  activite_secondaire: z.string().optional(),
  forme_juridique: z.string().optional(),
  regime_fiscal: z.string().optional(),
  capital: z.string().optional(),
  representant_civilite: z.string().optional(),
  representant_nom: z.string().optional(),
  representant_fonction: z.string().optional(),
  representant_telephone: z.string().optional(),
  representant_mobile: z.string().optional(),
})

const EMPTY = {
  code: '', nom: '', sigle: '',
  adresse: '', adresse_ligne2: '', code_postal: '', ville: '', pays: '',
  telephone: '', fax: '', email: '', site_web: '',
  activite_principale: '', activite_secondaire: '', forme_juridique: '', regime_fiscal: '', capital: '',
  representant_civilite: '', representant_nom: '', representant_fonction: '', representant_telephone: '', representant_mobile: '',
}

export default function SocieteListPage() {
  const [editing, setEditing] = useState(null)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['admin', 'societes', 1], queryFn: () => fetchSocietes(1) })

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

  const openEdit = (societe) => {
    reset({
      ...EMPTY,
      ...Object.fromEntries(Object.keys(EMPTY).map((key) => [key, societe[key] ?? ''])),
    })
    setEditing(societe)
  }

  const saveMutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, capital: values.capital || null }
      return editing?.id ? updateSociete(editing.id, payload) : createSociete(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'societes'] })
      setEditing(null)
    },
    onError: (error) => alert(error.response?.data?.message ?? 'Enregistrement impossible.'),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSociete,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'societes'] }),
    onError: (error) => {
      alert(error.response?.data?.message ?? 'Suppression impossible.')
    },
  })

  const activerMutation = useMutation({
    mutationFn: activerSociete,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'societes'] }),
    onError: (error) => alert(error.response?.data?.message ?? 'Activation impossible.'),
  })

  const desactiverMutation = useMutation({
    mutationFn: desactiverSociete,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'societes'] }),
    onError: (error) => alert(error.response?.data?.message ?? 'Désactivation impossible.'),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Sociétés</h1>
        <Button onClick={openCreate}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter une société
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
              {editing.id ? 'Modifier la société' : 'Nouvelle société'}
            </h2>
            <button type="button" onClick={() => setEditing(null)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>

          <FormSection icon={Building2} title="Identité">
            <div className="grid grid-cols-3 gap-4">
              <Input icon={Building2} label="Code" error={errors.code?.message} {...register('code')} />
              <Input icon={Building2} label="Nom" error={errors.nom?.message} {...register('nom')} />
              <Input icon={Building2} label="Sigle" error={errors.sigle?.message} {...register('sigle')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Building2} label="Activité principale" {...register('activite_principale')} />
              <Input icon={Building2} label="Activité secondaire" {...register('activite_secondaire')} />
            </div>
          </FormSection>

          <FormSection icon={Phone} title="Coordonnées">
            <div className="grid grid-cols-2 gap-4">
              <Input icon={MapPin} label="Adresse" {...register('adresse')} />
              <Input icon={MapPin} label="Complément d'adresse" {...register('adresse_ligne2')} />
            </div>
            <div className="grid grid-cols-3 gap-4">
              <Input icon={MapPin} label="Code postal" {...register('code_postal')} />
              <Input icon={MapPin} label="Ville" {...register('ville')} />
              <Input icon={MapPin} label="Pays" {...register('pays')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Phone} label="Téléphone" {...register('telephone')} />
              <Input icon={Phone} label="Fax" {...register('fax')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Mail} label="Email" error={errors.email?.message} {...register('email')} />
              <Input icon={Building2} label="Site web" {...register('site_web')} />
            </div>
          </FormSection>

          <FormSection icon={Scale} title="Informations légales">
            <div className="grid grid-cols-3 gap-4">
              <Input icon={Scale} label="Forme juridique" {...register('forme_juridique')} />
              <Input icon={Scale} label="Régime fiscal" {...register('regime_fiscal')} />
              <Input icon={Landmark} label="Capital" {...register('capital')} />
            </div>
          </FormSection>

          <FormSection icon={User} title="Représentant">
            <div className="grid grid-cols-3 gap-4">
              <Input icon={User} label="Civilité" {...register('representant_civilite')} />
              <Input icon={User} label="Nom & prénom" {...register('representant_nom')} />
              <Input icon={User} label="Fonction" {...register('representant_fonction')} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Input icon={Phone} label="Téléphone" {...register('representant_telephone')} />
              <Input icon={Phone} label="Mobile" {...register('representant_mobile')} />
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
              <th className="px-4 py-3 font-medium">Ville</th>
              <th className="px-4 py-3 font-medium">Établissements</th>
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
            {data?.data?.map((societe) => (
              <tr key={societe.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{societe.code}</td>
                <td className="px-4 py-3 text-slate-600">{societe.nom}</td>
                <td className="px-4 py-3 text-slate-600">{societe.ville ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{societe.etablissements_count}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                      societe.statut === 'actif' ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'
                    }`}
                  >
                    {societe.statut}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    {societe.statut !== 'actif' && (
                      <button
                        onClick={() => activerMutation.mutate(societe.id)}
                        className="rounded px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-50"
                      >
                        Activer
                      </button>
                    )}
                    {societe.statut === 'actif' && (
                      <button
                        onClick={() => desactiverMutation.mutate(societe.id)}
                        className="rounded px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100"
                      >
                        Désactiver
                      </button>
                    )}
                    <button
                      onClick={() => openEdit(societe)}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </button>
                    <button
                      onClick={() => confirm(`Supprimer la société ${societe.nom} ?`) && deleteMutation.mutate(societe.id)}
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
