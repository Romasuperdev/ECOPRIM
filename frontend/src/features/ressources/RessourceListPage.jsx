import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, X, ExternalLink } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { fetchRessources, createRessource, deleteRessource } from './ressourcesApi'
import { fetchMatieres, fetchNiveaux } from '../reference/referenceApi'

const schema = z.object({
  titre: z.string().min(1, 'Le titre est requis').max(150),
  url: z.string().url('URL invalide'),
  matiere_id: z.string().optional(),
  niveau_id: z.string().optional(),
  description: z.string().optional(),
})

export default function RessourceListPage() {
  const [showForm, setShowForm] = useState(false)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['ressources'], queryFn: () => fetchRessources(1) })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })
  const { data: niveaux } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveaux })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm({ resolver: zodResolver(schema) })

  const createMutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, matiere_id: values.matiere_id || null, niveau_id: values.niveau_id || null }
      return createRessource(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ressources'] })
      reset({ titre: '', url: '', matiere_id: '', niveau_id: '', description: '' })
      setShowForm(false)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteRessource,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ressources'] }),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Ressources pédagogiques</h1>
        <Button onClick={() => setShowForm((v) => !v)}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter une ressource
          </span>
        </Button>
      </div>

      {showForm && (
        <form
          onSubmit={handleSubmit((values) => createMutation.mutate(values))}
          className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">Nouvelle ressource</h2>
            <button type="button" onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>
          <Input label="Titre" error={errors.titre?.message} {...register('titre')} />
          <Input label="Lien (URL)" error={errors.url?.message} {...register('url')} />
          <div className="grid grid-cols-2 gap-4">
            <Select label="Matière (optionnel)" {...register('matiere_id')}>
              <option value="">—</option>
              {matieres?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.libelle}
                </option>
              ))}
            </Select>
            <Select label="Niveau (optionnel)" {...register('niveau_id')}>
              <option value="">—</option>
              {niveaux?.map((n) => (
                <option key={n.id} value={n.id}>
                  {n.libelle}
                </option>
              ))}
            </Select>
          </div>
          <Textarea label="Description" rows={3} {...register('description')} />
          <div className="flex justify-end">
            <Button type="submit" disabled={createMutation.isPending}>
              Ajouter
            </Button>
          </div>
        </form>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        {isLoading && <p className="text-slate-400">Chargement…</p>}
        {!isLoading && data?.data.length === 0 && <p className="text-slate-400">Aucune ressource pour l'instant.</p>}
        {data?.data.map((ressource) => (
          <div key={ressource.id} className="rounded-xl border border-slate-200 bg-white p-5">
            <div className="mb-2 flex items-start justify-between">
              <a
                href={ressource.url}
                target="_blank"
                rel="noreferrer"
                className="flex items-center gap-1 font-semibold text-primary-700 hover:underline"
              >
                {ressource.titre} <ExternalLink size={14} />
              </a>
              <button
                onClick={() => confirm(`Supprimer "${ressource.titre}" ?`) && deleteMutation.mutate(ressource.id)}
                className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </div>
            <div className="mb-2 flex gap-2 text-xs text-slate-500">
              {ressource.matiere && (
                <span className="rounded-full bg-slate-100 px-2 py-0.5">{ressource.matiere.libelle}</span>
              )}
              {ressource.niveau && (
                <span className="rounded-full bg-slate-100 px-2 py-0.5">{ressource.niveau.libelle}</span>
              )}
            </div>
            {ressource.description && <p className="text-sm text-slate-600">{ressource.description}</p>}
          </div>
        ))}
      </div>
    </div>
  )
}
