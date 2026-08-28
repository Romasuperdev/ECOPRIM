import { useParams, Link } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ShieldCheck, Building2, School, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import FormSection from '../../components/ui/FormSection'
import { fetchUtilisateur, fetchAllSocietes, fetchEtablissements, fetchRoles, createAffectation, terminerAffectation } from './adminApi'

const schema = z.object({
  societe_id: z.string().optional(),
  etablissement_id: z.string().optional(),
  role_id: z.string().min(1, 'Le rôle est requis'),
})

export default function UtilisateurDetailPage() {
  const { id } = useParams()
  const queryClient = useQueryClient()

  const { data: user } = useQuery({ queryKey: ['admin', 'utilisateurs', id], queryFn: () => fetchUtilisateur(id) })
  const { data: societes } = useQuery({ queryKey: ['admin', 'societes', 'all'], queryFn: fetchAllSocietes })
  const { data: etablissements } = useQuery({ queryKey: ['admin', 'etablissements', 'all'], queryFn: () => fetchEtablissements(1) })
  const { data: roles } = useQuery({ queryKey: ['admin', 'roles'], queryFn: fetchRoles })

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors },
  } = useForm({ resolver: zodResolver(schema) })

  const societeId = watch('societe_id')

  // Un établissement appartient à une seule société : une fois une société choisie, on ne
  // propose que ses établissements (une société peut en avoir plusieurs).
  const etablissementsFiltres = etablissements?.data?.filter(
    (etab) => !societeId || String(etab.societe_id) === societeId
  )

  const handleEtablissementChange = (e) => {
    const etabId = e.target.value
    register('etablissement_id').onChange(e)
    const etab = etablissements?.data?.find((item) => String(item.id) === etabId)
    if (etab) setValue('societe_id', String(etab.societe_id))
  }

  const affecterMutation = useMutation({
    mutationFn: (values) =>
      createAffectation({
        user_id: Number(id),
        societe_id: values.societe_id || null,
        etablissement_id: values.etablissement_id || null,
        role_id: values.role_id,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs', id] })
      reset({ societe_id: '', etablissement_id: '', role_id: '' })
    },
    onError: (error) => alert(error.response?.data?.message ?? 'Affectation impossible.'),
  })

  const terminerMutation = useMutation({
    mutationFn: terminerAffectation,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'utilisateurs', id] }),
  })

  if (!user) return null

  return (
    <div className="max-w-3xl">
      <Link to="/admin/utilisateurs" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour à la liste
      </Link>
      <h1 className="mb-1 text-2xl font-bold text-slate-800">{user.name}</h1>
      <p className="mb-6 text-slate-500">{user.email}</p>

      <div className="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Société</th>
              <th className="px-4 py-3 font-medium">Établissement</th>
              <th className="px-4 py-3 font-medium">Rôle</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {!user.affectations?.length && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Aucune affectation
                </td>
              </tr>
            )}
            {user.affectations?.map((aff) => (
              <tr key={aff.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{aff.societe?.nom ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{aff.etablissement?.nom ?? '—'}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{aff.role?.name}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                      aff.actif ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'
                    }`}
                  >
                    {aff.actif ? 'Active' : `Terminée${aff.date_fin ? ` le ${aff.date_fin}` : ''}`}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  {aff.actif && (
                    <button
                      onClick={() => confirm('Terminer cette affectation ?') && terminerMutation.mutate(aff.id)}
                      className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                      title="Terminer l'affectation"
                    >
                      <X size={16} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <form
        onSubmit={handleSubmit((values) => affecterMutation.mutate(values))}
        className="space-y-8 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <FormSection icon={ShieldCheck} title="Nouvelle affectation">
          <div className="grid grid-cols-3 gap-4">
            <Select icon={Building2} label="Société (optionnel)" {...register('societe_id')}>
              <option value="">— Aucune —</option>
              {societes?.map((societe) => (
                <option key={societe.id} value={societe.id}>
                  {societe.nom}
                </option>
              ))}
            </Select>
            <Select
              icon={School}
              label="Établissement (optionnel)"
              {...register('etablissement_id')}
              onChange={handleEtablissementChange}
            >
              <option value="">— Aucun —</option>
              {etablissementsFiltres?.map((etab) => (
                <option key={etab.id} value={etab.id}>
                  {etab.nom}
                </option>
              ))}
            </Select>
            <Select icon={ShieldCheck} label="Rôle" error={errors.role_id?.message} {...register('role_id')}>
              <option value="">— Sélectionner —</option>
              {roles?.map((role) => (
                <option key={role.id} value={role.id}>
                  {role.name}
                </option>
              ))}
            </Select>
          </div>
        </FormSection>

        <div className="flex justify-end">
          <Button type="submit" disabled={affecterMutation.isPending}>
            Affecter
          </Button>
        </div>
      </form>
    </div>
  )
}
