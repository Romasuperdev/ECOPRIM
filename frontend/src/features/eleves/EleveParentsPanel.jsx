import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2, Link2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import { attachEleveToParent, detachEleveFromParent, fetchAllParents } from '../parents/parentsApi'

const LIEN_LABELS = { pere: 'Père', mere: 'Mère', tuteur: 'Tuteur' }

export default function EleveParentsPanel({ eleve }) {
  const [parentId, setParentId] = useState('')
  const [lien, setLien] = useState('pere')
  const queryClient = useQueryClient()

  const { data: parents } = useQuery({ queryKey: ['parents', 'all'], queryFn: fetchAllParents })

  const attachMutation = useMutation({
    mutationFn: () => attachEleveToParent(parentId, eleve.id, lien),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['eleves', String(eleve.id)] })
      setParentId('')
    },
  })

  const detachMutation = useMutation({
    mutationFn: (parentId) => detachEleveFromParent(parentId, eleve.id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['eleves', String(eleve.id)] }),
  })

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6">
      <h2 className="mb-4 text-lg font-semibold text-slate-800">Parents rattachés</h2>

      {eleve.parents?.length ? (
        <ul className="mb-4 divide-y divide-slate-100">
          {eleve.parents.map((parent) => (
            <li key={parent.id} className="flex items-center justify-between py-2 text-sm">
              <span>
                {parent.prenom} {parent.nom}{' '}
                <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                  {LIEN_LABELS[parent.pivot.lien_parente] ?? parent.pivot.lien_parente}
                </span>
                {parent.telephone && <span className="ml-2 text-slate-400">{parent.telephone}</span>}
              </span>
              <button
                onClick={() => detachMutation.mutate(parent.id)}
                className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
              >
                <Trash2 size={16} />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mb-4 text-sm text-slate-400">Aucun parent rattaché.</p>
      )}

      <div className="flex items-end gap-2 border-t border-slate-100 pt-4">
        <div className="flex-1">
          <Select label="Rattacher un parent existant" value={parentId} onChange={(e) => setParentId(e.target.value)}>
            <option value="">— Sélectionner —</option>
            {parents?.map((parent) => (
              <option key={parent.id} value={parent.id}>
                {parent.prenom} {parent.nom}
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
          disabled={!parentId || attachMutation.isPending}
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
