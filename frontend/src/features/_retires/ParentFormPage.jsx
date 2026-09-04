import { useEffect } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, Phone, Mail, Briefcase, MapPin } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import FormSection from '../../components/ui/FormSection'
import { fetchParent, createParent, updateParent } from './parentsApi'
import ParentElevesPanel from './ParentElevesPanel'

const schema = z.object({
  nom: z.string().min(1, 'Le nom est requis').max(100),
  prenom: z.string().min(1, 'Le prénom est requis').max(100),
  telephone: z.string().optional(),
  email: z.union([z.literal(''), z.string().email('Email invalide')]).optional(),
  profession: z.string().optional(),
  adresse: z.string().optional(),
})

export default function ParentFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: parent } = useQuery({
    queryKey: ['parents', id],
    queryFn: () => fetchParent(id),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (parent) reset(parent)
  }, [parent, reset])

  const mutation = useMutation({
    mutationFn: (values) => {
      const payload = { ...values, email: values.email || null }
      return isEdit ? updateParent(id, payload) : createParent(payload)
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['parents-page'] })
      if (!isEdit) {
        navigate(`/parents/${data.id}/modifier`);
      }
    },
  })

  return (
    <div className="max-w-xl">
      <Link to="/parents" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">
        {isEdit ? 'Modifier le parent' : 'Ajouter un parent'}
      </h1>

      <form
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={User} title="Identité">
          <div className="grid grid-cols-2 gap-4">
            <Input icon={User} label="Nom" error={errors.nom?.message} {...register('nom')} />
            <Input icon={User} label="Prénom" error={errors.prenom?.message} {...register('prenom')} />
          </div>
        </FormSection>

        <FormSection icon={Phone} title="Coordonnées">
          <div className="grid grid-cols-2 gap-4">
            <Input icon={Phone} label="Téléphone" error={errors.telephone?.message} {...register('telephone')} />
            <Input icon={Mail} label="Email" error={errors.email?.message} {...register('email')} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Input icon={Briefcase} label="Profession" error={errors.profession?.message} {...register('profession')} />
            <Input icon={MapPin} label="Adresse" error={errors.adresse?.message} {...register('adresse')} />
          </div>
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

      {isEdit && parent && (
        <div className="mt-6">
          <ParentElevesPanel parent={parent} />
        </div>
      )}
    </div>
  )
}
