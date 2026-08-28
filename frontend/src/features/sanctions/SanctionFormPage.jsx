import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, CalendarDays, ShieldAlert, ClipboardList } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchSanction, createSanction, updateSanction } from './sanctionsApi'
import { fetchAllEleves } from '../reference/referenceApi'

const schema = z.object({
  eleve_id: z.string().min(1, "L'élève est requis"),
  date_sanction: z.string().min(1, 'La date est requise'),
  faute: z.string().min(1, 'La faute est requise').max(255),
  sanction: z.string().min(1, 'La sanction est requise').max(255),
  observation: z.string().optional(),
})

export default function SanctionFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })

  const { data: sanction } = useQuery({
    queryKey: ['sanctions', id],
    queryFn: () => fetchSanction(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (sanction) {
      reset({
        eleve_id: String(sanction.eleve_id),
        date_sanction: sanction.date_sanction,
        faute: sanction.faute,
        sanction: sanction.sanction,
        observation: sanction.observation ?? '',
      })
    }
  }, [sanction, reset])

  const mutation = useMutation({
    mutationFn: (values) => (isEdit ? updateSanction(id, values) : createSanction(values)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sanctions'] })
      navigate('/sanctions')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/sanctions" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier la sanction' : 'Ajouter une sanction'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={User} title="Élève">
          <Select icon={User} label="Élève" error={errors.eleve_id?.message} {...register('eleve_id')}>
            <option value="">— Sélectionner —</option>
            {eleves?.map((eleve) => (
              <option key={eleve.id} value={eleve.id}>
                {eleve.prenom} {eleve.nom} ({eleve.matricule})
              </option>
            ))}
          </Select>

          <Input type="date" icon={CalendarDays} label="Date" error={errors.date_sanction?.message} {...register('date_sanction')} />
        </FormSection>

        <FormSection icon={ShieldAlert} title="Sanction">
          <Input icon={ShieldAlert} label="Faute constatée" error={errors.faute?.message} {...register('faute')} />
          <Input icon={ShieldAlert} label="Sanction appliquée" error={errors.sanction?.message} {...register('sanction')} />
          <Input icon={ClipboardList} label="Observation" error={errors.observation?.message} {...register('observation')} />
        </FormSection>

        {mutation.isError && (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">
            Une erreur est survenue. Vérifiez les champs et réessayez.
          </p>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="submit" disabled={isSubmitting || mutation.isPending}>
            {isEdit ? 'Enregistrer' : 'Créer'}
          </Button>
        </div>
      </form>
    </div>
  )
}
