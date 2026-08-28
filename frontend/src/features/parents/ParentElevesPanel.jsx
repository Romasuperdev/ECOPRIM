import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, Link2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import { attachEleveToParent, detachEleveFromParent } from './parentsApi'
import { fetchAllEleves } from '../reference/referenceApi'

const LIEN_LABELS = { pere: 'Père', mere: 'Mère', tuteur: 'Tuteur' }

export default function ParentElevesPanel({ parent }) {
  const [eleveId, setEleveId] = useState('')
  const [lien, setLien] = useState('pere')
  const queryClient = useQueryClient()

  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })

  const attachMutation = useMutation({
    mutationFn: () => attachEleveToParent(parent.id, eleveId, lien),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parents', String(parent.id)] })
      setEleveId('')
    },
  })

  const detachMutation = useMutation({
    mutationFn: (eleveId) => detachEleveFromParent(parent.id, eleveId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['parents', String(parent.id)] }),
  })

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6">
      <h2 className="mb-4 text-lg font-semibold text-slate-800">Élèves rattachés</h2>

      {parent.eleves?.length ? (
        <ul className="mb-4 divide-y divide-slate-100">
          {parent.eleves.map((eleve) => (
            <li key={eleve.id} className="flex items-center justify-between py-2 text-sm">
              <span>
                {eleve.prenom} {eleve.nom}{' '}
                <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                  {LIEN_LABELS[eleve.pivot.lien_parente] ?? eleve.pivot.lien_parente}
                </span>
              </span>
              <button
                onClick={() => detachMutation.mutate(eleve.id)}
                className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mb-4 text-sm text-slate-400">Aucun élève rattaché pour l'instant.</p>
      )}

      <div className="flex items-end gap-2 border-t border-slate-100 pt-4">
        <div className="flex-1">
          <Select label="Élève" value={eleveId} onChange={(e) => setEleveId(e.target.value)}>
            <option value="">— Sélectionner —</option>
            {eleves?.map((eleve) => (
              <option key={eleve.id} value={eleve.id}>
                {eleve.prenom} {eleve.nom}
              </option>
            ))}
          </Select>
        </div>
        <div className="w-32">
          <Select label="Lien" value={lien} onChange={(e) => setLien(e.target.value)}>
            <option value="pere">Père</option>
            <option value="mere">Mère</option>
            <option value="tuteur">Tuteur</option>
          </Select>
        </div>
        <Button
          variant="outline"
          disabled={!eleveId || attachMutation.isPending}
          onClick={() => attachMutation.mutate()}
        >
          <span className="flex items-center gap-2">
            <Link2 size={16} /> Rattacher
          </span>
        </Button>
      </div>
    </div>
  )
}
