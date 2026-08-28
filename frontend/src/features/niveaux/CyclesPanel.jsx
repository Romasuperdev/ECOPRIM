import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import { fetchCycles, createCycle, deleteCycle } from './cyclesApi'

export default function CyclesPanel() {
  const [showForm, setShowForm] = useState(false)
  const queryClient = useQueryClient()

  const { data: cycles, isLoading } = useQuery({ queryKey: ['cycles'], queryFn: fetchCycles })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm()

  const createMutation = useMutation({
    mutationFn: createCycle,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['cycles'] })
      reset()
      setShowForm(false)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCycle,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['cycles'] }),
    onError: (error) => alert(error.response?.data?.message ?? 'Suppression impossible.'),
  })

  return (
    <div className="mb-6 rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-800">Cycles</h2>
        <Button variant="outline" onClick={() => setShowForm((v) => !v)}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter un cycle
          </span>
        </Button>
      </div>

      {showForm && (
        <form
          onSubmit={handleSubmit((values) => createMutation.mutate({ ...values, ordre: values.ordre ? Number(values.ordre) : 0 }))}
          className="mb-4 space-y-3 rounded-lg border border-slate-100 bg-slate-50 p-4"
        >
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-slate-600">Nouveau cycle</span>
            <button type="button" onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
              <X size={16} />
            </button>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <Input label="Code" error={errors.code?.message} {...register('code', { required: 'Requis' })} />
            <Input label="Libellé" error={errors.libelle?.message} {...register('libelle', { required: 'Requis' })} />
            <Input type="number" label="Ordre" {...register('ordre')} />
          </div>
          <div className="flex justify-end">
            <Button type="submit" disabled={createMutation.isPending}>
              Ajouter
            </Button>
          </div>
        </form>
      )}

      {isLoading ? (
        <p className="text-sm text-slate-400">Chargement…</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {cycles?.map((cycle) => (
            <li key={cycle.id} className="flex items-center justify-between py-2 text-sm">
              <span>
                <span className="font-medium text-slate-800">{cycle.libelle}</span>{' '}
                <span className="text-slate-400">({cycle.code})</span>
              </span>
              <button
                onClick={() => deleteMutation.mutate(cycle.id)}
                className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
