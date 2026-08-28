import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { fetchNiveauxList, createNiveau, updateNiveau, deleteNiveau } from './niveauxApi'
import { fetchCycles } from './cyclesApi'
import CyclesPanel from './CyclesPanel'

const schema = z.object({
  code: z.string().min(1, 'Le code est requis').max(20),
  libelle: z.string().min(1, 'Le libellé est requis').max(100),
  ordre: z.string().optional(),
  cycle_id: z.string().optional(),
})

export default function NiveauListPage() {
  const [editing, setEditing] = useState(null) // null = fermé, {} = création, {id,...} = édition
  const queryClient = useQueryClient()

  const { data: niveaux, isLoading } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveauxList })
  const { data: cycles } = useQuery({ queryKey: ['cycles'], queryFn: fetchCycles })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm({ resolver: zodResolver(schema) })

  const openCreate = () => {
    reset({ code: '', libelle: '', ordre: '', cycle_id: '' })
    setEditing({})
  }

  const openEdit = (niveau) => {
    reset({
      code: niveau.code,
      libelle: niveau.libelle,
      ordre: String(niveau.ordre ?? ''),
      cycle_id: niveau.cycle_id ? String(niveau.cycle_id) : '',
    })
    setEditing(niveau)
  }

  const saveMutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, ordre: values.ordre ? Number(values.ordre) : 0, cycle_id: values.cycle_id || null }
      return editing?.id ? updateNiveau(editing.id, payload) : createNiveau(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['niveaux'] })
      setEditing(null)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deleteNiveau,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['niveaux'] }),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Cycles / Niveaux</h1>
        <Button onClick={openCreate}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter un niveau
          </span>
        </Button>
      </div>

      <CyclesPanel />

      {editing && (
        <form
          onSubmit={handleSubmit((values) => saveMutation.mutate(values))}
          className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">
              {editing.id ? 'Modifier le niveau' : 'Nouveau niveau'}
            </h2>
            <button type="button" onClick={() => setEditing(null)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>
          <div className="grid grid-cols-4 gap-4">
            <Input label="Code" error={errors.code?.message} {...register('code')} />
            <Input label="Libellé" error={errors.libelle?.message} {...register('libelle')} />
            <Select label="Cycle" error={errors.cycle_id?.message} {...register('cycle_id')}>
              <option value="">—</option>
              {cycles?.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.libelle}
                </option>
              ))}
            </Select>
            <Input type="number" label="Ordre" error={errors.ordre?.message} {...register('ordre')} />
          </div>
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
              <th className="px-4 py-3 font-medium">Ordre</th>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Cycle</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {niveaux?.map((niveau) => (
              <tr key={niveau.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{niveau.ordre}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{niveau.code}</td>
                <td className="px-4 py-3 text-slate-600">{niveau.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{niveau.cycle?.libelle ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <button
                      onClick={() => openEdit(niveau)}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </button>
                    <button
                      onClick={() => confirm(`Supprimer le niveau ${niveau.libelle} ?`) && deleteMutation.mutate(niveau.id)}
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
