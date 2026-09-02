import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, Hash, Phone, Mail, ListChecks } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchEnseignant, createEnseignant, updateEnseignant, desactiverEnseignant } from './enseignantsApi'

const schema = z.object({
  matricule: z.string().min(1, 'Le matricule est requis').max(30),
  nom: z.string().min(1, 'Le nom est requis').max(100),
  prenom: z.string().min(1, 'Le prénom est requis').max(100),
  email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  telephone: z.string().optional(),
  statut: z.string().optional(),
})

export default function EnseignantFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: enseignant } = useQuery({
    queryKey: ['enseignants', id],
    queryFn: () => fetchEnseignant(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema), defaultValues: { statut: 'titulaire' } })

  useEffect(() => {
    if (enseignant) reset(enseignant)
  }, [enseignant, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, email: values.email || null }
      return isEdit ? updateEnseignant(id, payload) : createEnseignant(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['enseignants-page'] })
      queryClient.invalidateQueries({ queryKey: ['enseignants', 'all'] })
      navigate('/enseignants')
    },
  })

  const desactiverMutation = useMutation({
    mutationFn: () => desactiverEnseignant(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['enseignants', id] }),
  })

  return (
    <div className="max-w-xl">
      <Link to="/enseignants" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? "Modifier l'enseignant" : 'Ajouter un enseignant'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={User} title="Identité">
          <div className="grid grid-cols-2 gap-4">
            <Input icon={Hash} label="Matricule" error={errors.matricule?.message} {...register('matricule')} />
            <Input icon={Phone} label="Téléphone" error={errors.telephone?.message} {...register('telephone')} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Input icon={User} label="Nom" error={errors.nom?.message} {...register('nom')} />
            <Input icon={User} label="Prénom" error={errors.prenom?.message} {...register('prenom')} />
          </div>
        </FormSection>

        <FormSection icon={Mail} title="Coordonnées et statut">
          <div className="grid grid-cols-2 gap-4">
            <Input icon={Mail} label="Email" error={errors.email?.message} {...register('email')} />
            <Select icon={ListChecks} label="Statut" {...register('statut')}>
              <option value="titulaire">Titulaire</option>
              <option value="vacataire">Vacataire</option>
            </Select>
          </div>
        </FormSection>

        {isEdit && enseignant && !enseignant.actif && (
          <p className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-500">
            Cet enseignant est désactivé.
          </p>
        )}

        {mutation.isError && (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">
            Une erreur est survenue. Vérifiez les champs et réessayez.
          </p>
        )}

        <div className="flex items-center justify-between gap-2 pt-2">
          {isEdit && enseignant?.actif && (
            <Button
              type="button"
              variant="outline"
              onClick={() => confirm('Désactiver cet enseignant ?') && desactiverMutation.mutate()}
              disabled={desactiverMutation.isPending}
            >
              Désactiver
            </Button>
          )}
          <div className="ml-auto">
            <Button type="submit" disabled={isSubmitting || mutation.isPending}>
              {isEdit ? 'Enregistrer' : 'Créer'}
            </Button>
          </div>
        </div>
      </form>

    </div>
  )
}
