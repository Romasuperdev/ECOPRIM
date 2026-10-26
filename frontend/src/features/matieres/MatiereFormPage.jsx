import { useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpen, Hash, TrendingUp } from 'lucide-react'
import CarteFormulaire, { BoutonsFormulaire, GrilleFormulaire } from '../../components/ui/CarteFormulaire'
import Input from '../../components/ui/Input'
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
      // Toutes les requêtes « matieres » : la liste paginée (['matieres', page]), le
      // référentiel complet (['matieres', 'all']) et la fiche elle-même.
      queryClient.invalidateQueries({ queryKey: ['matieres'] })
      navigate('/matieres')
    },
    // Le refus du serveur s'affiche dans le récapitulatif ci-dessous : pas de message global.
    meta: { erreurAffichee: true },
  })

  // Récapitulatif : les erreurs de saisie, puis le refus du serveur (ses messages
  // précis s'il en donne, sinon le message générique d'avant).
  const erreurServeur = mutation.error?.response?.data
  const recap = [
    ...Object.values(errors).map((e) => e?.message),
    ...(mutation.isError
      ? (erreurServeur?.errors
          ? Object.values(erreurServeur.errors).flat()
          : ['Une erreur est survenue. Vérifiez les champs et réessayez.'])
      : []),
  ]

  return (
    <CarteFormulaire
      icon={BookOpen}
      titre={isEdit ? 'Modifier la matière' : 'Ajouter une matière'}
      retour="/matieres"
      iconeCarte={BookOpen}
      titreCarte={isEdit ? 'Modifier une matière' : 'Enregistrer une matière'}
      erreurs={recap}
      largeur="max-w-4xl"
    >
      <form onSubmit={handleSubmit((values) => mutation.mutate(values))} noValidate>
        <GrilleFormulaire>
          <Input icon={Hash} label="Code *" error={errors.code?.message} {...register('code')} />
          <Input icon={BookOpen} label="Libellé *" error={errors.libelle?.message} {...register('libelle')} />
          <Input
            type="number"
            min="1"
            icon={TrendingUp}
            label="Coefficient par défaut"
            error={errors.coefficient_defaut?.message}
            {...register('coefficient_defaut')}
          />
        </GrilleFormulaire>

        <BoutonsFormulaire
          annulerVers="/matieres"
          enCours={isSubmitting || mutation.isPending}
          libelle={isEdit ? 'Enregistrer' : 'Créer'}
        />
      </form>
    </CarteFormulaire>
  )
}
