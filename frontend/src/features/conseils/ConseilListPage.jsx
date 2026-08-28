import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, X, Trash2, ArrowRight } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { fetchConseils, createConseil, deleteConseil } from './conseilsApi'
import { fetchAllClasses, fetchPeriodes } from '../reference/referenceApi'

export default function ConseilListPage() {
  const [showForm, setShowForm] = useState(false)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({ queryKey: ['conseils'], queryFn: () => fetchConseils(1) })
  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: periodes } = useQuery({ queryKey: ['periodes'], queryFn: () => fetchPeriodes() })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm()

  const createMutation = useMutation({
    mutationFn: createConseil,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conseils'] })
      reset()
      setShowForm(false)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteConseil,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['conseils'] }),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Conseils de classe</h1>
        <Button onClick={() => setShowForm((v) => !v)}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Planifier un conseil
          </span>
        </Button>
      </div>

      {showForm && (
        <form
          onSubmit={handleSubmit((values) => createMutation.mutate(values))}
          className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">Nouveau conseil de classe</h2>
            <button type="button" onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>
          <div className="grid grid-cols-3 gap-4">
            <Select
              label="Classe"
              error={errors.classe_id?.message}
              {...register('classe_id', { required: 'La classe est requise' })}
            >
              <option value="">— Sélectionner —</option>
              {classes?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.nom}
                </option>
              ))}
            </Select>
            <Select
              label="Période"
              error={errors.periode_id?.message}
              {...register('periode_id', { required: 'La période est requise' })}
            >
              <option value="">— Sélectionner —</option>
              {periodes?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.libelle}
                </option>
              ))}
            </Select>
            <Input
              type="date"
              label="Date"
              error={errors.date_conseil?.message}
              {...register('date_conseil', { required: 'La date est requise' })}
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Input label="Président" {...register('president')} />
            <Input label="Secrétaire" {...register('secretaire')} />
          </div>
          <div className="flex justify-end">
            <Button type="submit" disabled={createMutation.isPending}>
              Créer
            </Button>
          </div>
        </form>
      )}

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Période</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                  Aucun conseil planifié.
                </td>
              </tr>
            )}
            {data?.data.map((conseil) => (
              <tr key={conseil.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{conseil.classe?.nom}</td>
                <td className="px-4 py-3 text-slate-600">{conseil.periode?.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{conseil.date_conseil}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <Link
                      to={`/conseils-classe/${conseil.id}`}
                      className="flex items-center gap-1 rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      Ouvrir <ArrowRight size={16} />
                    </Link>
                    <button
                      onClick={() => confirm('Supprimer ce conseil ?') && deleteMutation.mutate(conseil.id)}
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
