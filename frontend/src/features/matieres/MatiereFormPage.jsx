import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, BookOpen, Hash, TrendingUp } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import FormSection from '../../components/ui/FormSection'
import { fetchMatiere, createMatiere, updateMatiere } from './matieresApi'

const schema = z.object({
  code: z.string().min(1, 'Le code est requis').max(20),
  libelle: z.string().min(1, 'Le libellé est requis').max(100),
  coefficient_defaut: z.string().optional(),
})

export default function MatiereFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: matiere } = useQuery({
    queryKey: ['matieres', id],
    queryFn: () => fetchMatiere(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (matiere) {
      reset({
        code: matiere.code,
        libelle: matiere.libelle,
        coefficient_defaut: matiere.coefficient_defaut ? String(matiere.coefficient_defaut) : '',
      })
    }
  }, [matiere, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = {
        ...values,
        coefficient_defaut: values.coefficient_defaut ? Number(values.coefficient_defaut) : null,
      }
      return isEdit ? updateMatiere(id, payload) : createMatiere(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['matieres-page'] })
      queryClient.invalidateQueries({ queryKey: ['matieres', 'all'] })
      navigate('/matieres')
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/matieres" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier la matière' : 'Ajouter une matière'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={BookOpen} title="Informations générales">
          <div className="grid grid-cols-2 gap-4">
            <Input icon={Hash} label="Code" error={errors.code?.message} {...register('code')} />
            <Input
              type="number"
              min="1"
              icon={TrendingUp}
              label="Coefficient par défaut"
              error={errors.coefficient_defaut?.message}
              {...register('coefficient_defaut')}
            />
          </div>
          <Input icon={BookOpen} label="Libellé" error={errors.libelle?.message} {...register('libelle')} />
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
