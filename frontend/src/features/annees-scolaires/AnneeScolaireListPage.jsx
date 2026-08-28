import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, X, Users } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import {
  fetchAnneesScolairesList,
  createAnneeScolaire,
  updateAnneeScolaire,
  deleteAnneeScolaire,
  proposerReinscriptions,
} from './anneesScolairesApi'

const schema = z.object({
  libelle: z.string().min(1, 'Le libellé est requis').max(20),
  date_debut: z.string().min(1, 'La date de début est requise'),
  date_fin: z.string().min(1, 'La date de fin est requise'),
  active: z.boolean().optional(),
  cloturee: z.boolean().optional(),
})

export default function AnneeScolaireListPage() {
  const [editing, setEditing] = useState(null)
  const queryClient = useQueryClient()

  const { data: annees, isLoading } = useQuery({
    queryKey: ['annees-scolaires'],
    queryFn: fetchAnneesScolairesList,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm({ resolver: zodResolver(schema) })

  const openCreate = () => {
    reset({ libelle: '', date_debut: '', date_fin: '', active: false, cloturee: false })
    setEditing({})
  }

  const openEdit = (annee) => {
    reset({
      libelle: annee.libelle,
      date_debut: annee.date_debut,
      date_fin: annee.date_fin,
      active: annee.active,
      cloturee: annee.cloturee,
    })
    setEditing(annee)
  }

  const saveMutation = useMutation({
    mutationFn: (values) => (editing?.id ? updateAnneeScolaire(editing.id, values) : createAnneeScolaire(values)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['annees-scolaires'] })
      setEditing(null)
    },
    onError: (error) => alert(error.response?.data?.errors?.date_debut?.[0] ?? 'Erreur de validation.'),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteAnneeScolaire,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['annees-scolaires'] }),
  })

  const reinscriptionMutation = useMutation({
    mutationFn: proposerReinscriptions,
    onSuccess: (resultat) => {
      alert(
        `${resultat.traites} élève(s) traité(s) : ${resultat.avec_classe} affecté(s) automatiquement à une classe, ${resultat.sans_classe} à affecter manuellement.`
      )
    },
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Années scolaires</h1>
        <Button onClick={openCreate}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Ajouter une année scolaire
          </span>
        </Button>
      </div>

      {editing && (
        <form
          onSubmit={handleSubmit((values) => saveMutation.mutate(values))}
          className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">
              {editing.id ? "Modifier l'année scolaire" : 'Nouvelle année scolaire'}
            </h2>
            <button type="button" onClick={() => setEditing(null)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>
          <div className="grid grid-cols-3 gap-4">
            <Input label="Libellé (ex: 2026-2027)" error={errors.libelle?.message} {...register('libelle')} />
            <Input type="date" label="Date de début" error={errors.date_debut?.message} {...register('date_debut')} />
            <Input type="date" label="Date de fin" error={errors.date_fin?.message} {...register('date_fin')} />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="rounded border-slate-300" {...register('active')} />
            Année active (désactive automatiquement les autres)
          </label>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="rounded border-slate-300" {...register('cloturee')} />
            Année clôturée (lecture seule : notes, absences, effectifs bloqués)
          </label>
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
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Début</th>
              <th className="px-4 py-3 font-medium">Fin</th>
              <th className="px-4 py-3 font-medium">Statut</th>
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
            {annees?.map((annee) => (
              <tr key={annee.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{annee.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{annee.date_debut}</td>
                <td className="px-4 py-3 text-slate-600">{annee.date_fin}</td>
                <td className="px-4 py-3">
                  <div className="flex gap-1">
                    {annee.active && (
                      <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">
                        Active
                      </span>
                    )}
                    {annee.cloturee && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                        Clôturée
                      </span>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <button
                      onClick={() =>
                        confirm(
                          `Proposer la réinscription en masse des élèves actifs vers « ${annee.libelle} » ?`
                        ) && reinscriptionMutation.mutate(annee.id)
                      }
                      title="Proposer les réinscriptions"
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Users size={16} />
                    </button>
                    <button
                      onClick={() => openEdit(annee)}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Pencil size={16} />
                    </button>
                    <button
                      onClick={() => confirm(`Supprimer l'année ${annee.libelle} ?`) && deleteMutation.mutate(annee.id)}
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
