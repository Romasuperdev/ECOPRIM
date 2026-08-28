import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, X, Trash2, Megaphone } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { fetchAnnonces, createAnnonce, deleteAnnonce } from './messagesApi'
import { fetchAllClasses } from '../reference/referenceApi'

export default function AnnoncesPage() {
  const [showForm, setShowForm] = useState(false)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['annonces'], queryFn: () => fetchAnnonces(1) })
  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm()

  const createMutation = useMutation({
    mutationFn: (values) => createAnnonce({ ...values, classe_id: values.classe_id || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['annonces'] })
      reset()
      setShowForm(false)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteAnnonce,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['annonces'] }),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Annonces</h1>
        <Button onClick={() => setShowForm((v) => !v)}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Nouvelle annonce
          </span>
        </Button>
      </div>

      {showForm && (
        <form
          onSubmit={handleSubmit((values) => createMutation.mutate(values))}
          className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">Nouvelle annonce</h2>
            <button type="button" onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>
          <Input label="Titre" error={errors.titre?.message} {...register('titre', { required: 'Le titre est requis' })} />
          <Select label="Destinée à (optionnel)" {...register('classe_id')}>
            <option value="">Toute l'école</option>
            {classes?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.nom}
              </option>
            ))}
          </Select>
          <Textarea
            label="Contenu"
            rows={4}
            error={errors.contenu?.message}
            {...register('contenu', { required: 'Le contenu est requis' })}
          />
          <div className="flex justify-end">
            <Button type="submit" disabled={createMutation.isPending}>
              Publier
            </Button>
          </div>
        </form>
      )}

      <div className="space-y-3">
        {isLoading && <p className="text-slate-400">Chargement…</p>}
        {!isLoading && data?.data.length === 0 && <p className="text-slate-400">Aucune annonce.</p>}
        {data?.data.map((annonce) => (
          <div key={annonce.id} className="rounded-xl border border-slate-200 bg-white p-5">
            <div className="mb-1 flex items-start justify-between">
              <span className="flex items-center gap-2 font-semibold text-slate-800">
                <Megaphone size={16} className="text-secondary-500" />
                {annonce.titre}
              </span>
              <button
                onClick={() => confirm('Supprimer cette annonce ?') && deleteMutation.mutate(annonce.id)}
                className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </div>
            <p className="mb-2 text-sm text-slate-600">{annonce.contenu}</p>
            <p className="text-xs text-slate-400">
              {annonce.auteur?.name} · {annonce.classe?.nom ?? 'Toute l\'école'} ·{' '}
              {new Date(annonce.created_at).toLocaleDateString('fr-FR')}
            </p>
          </div>
        ))}
      </div>
    </div>
  )
}
