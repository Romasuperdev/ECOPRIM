import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, Link2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import { fetchIntervenants, addIntervenant, removeIntervenant } from './classesApi'
import { fetchMatieres, fetchEnseignants, fetchAnneesScolaires } from '../reference/referenceApi'

export default function ClasseIntervenantsPanel({ classe }) {
  const [matiereId, setMatiereId] = useState('')
  const [enseignantId, setEnseignantId] = useState('')
  const queryClient = useQueryClient()

  const { data: intervenants } = useQuery({
    queryKey: ['classes', classe.id, 'intervenants'],
    queryFn: () => fetchIntervenants(classe.id),
  })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })
  const { data: enseignants } = useQuery({ queryKey: ['enseignants', 'all'], queryFn: fetchEnseignants })
  const { data: anneesScolaires } = useQuery({ queryKey: ['annees-scolaires'], queryFn: fetchAnneesScolaires })

  const anneeScolaireId = classe.annee_scolaire_id ?? anneesScolaires?.find((a) => a.active)?.id

  const addMutation = useMutation({
    mutationFn: () =>
      addIntervenant(classe.id, {
        matiere_id: matiereId,
        enseignant_id: enseignantId,
        annee_scolaire_id: anneeScolaireId,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['classes', classe.id, 'intervenants'] })
      setMatiereId('')
      setEnseignantId('')
    },
  })

  const removeMutation = useMutation({
    mutationFn: (intervenantId) => removeIntervenant(classe.id, intervenantId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['classes', classe.id, 'intervenants'] }),
  })

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6">
      <h2 className="mb-1 text-lg font-semibold text-slate-800">Intervenants</h2>
      <p className="mb-4 text-sm text-slate-500">
        Enseignants intervenant sur une matière spécifique, en plus de l'enseignant principal.
      </p>

      {intervenants?.length ? (
        <ul className="mb-4 divide-y divide-slate-100">
          {intervenants.map((intervenant) => (
            <li key={intervenant.id} className="flex items-center justify-between py-2 text-sm">
              <span>
                <strong>{intervenant.matiere?.libelle}</strong> — {intervenant.enseignant?.prenom}{' '}
                {intervenant.enseignant?.nom}
              </span>
              <button
                onClick={() => removeMutation.mutate(intervenant.id)}
                className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mb-4 text-sm text-slate-400">Aucun intervenant pour l'instant.</p>
      )}

      <div className="flex items-end gap-2 border-t border-slate-100 pt-4">
        <div className="flex-1">
          <Select label="Matière" value={matiereId} onChange={(e) => setMatiereId(e.target.value)}>
            <option value="">— Sélectionner —</option>
            {matieres?.map((matiere) => (
              <option key={matiere.id} value={matiere.id}>
                {matiere.libelle}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex-1">
          <Select label="Enseignant" value={enseignantId} onChange={(e) => setEnseignantId(e.target.value)}>
            <option value="">— Sélectionner —</option>
            {enseignants?.map((enseignant) => (
              <option key={enseignant.id} value={enseignant.id}>
                {enseignant.prenom} {enseignant.nom}
              </option>
            ))}
          </Select>
        </div>
        <Button
          variant="outline"
          disabled={!matiereId || !enseignantId || !anneeScolaireId || addMutation.isPending}
          onClick={() => addMutation.mutate()}
        >
          <span className="flex items-center gap-2">
            <Link2 size={16} /> Ajouter
          </span>
        </Button>
      </div>
    </div>
  )
}
