import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Users } from 'lucide-react'
import Select from '../../components/ui/Select'
import { fetchAllEleves } from '../reference/referenceApi'
import DocumentsPanel from './DocumentsPanel'

export default function DocumentsElevesPage() {
  const [eleveId, setEleveId] = useState('')

  const { data: eleves } = useQuery({ queryKey: ['eleves', 'all'], queryFn: fetchAllEleves })

  const eleve = eleves?.find((el) => String(el.id) === String(eleveId))

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Documents élèves</h1>
        <p className="mt-1 text-sm text-slate-500">
          Sélectionnez un élève pour consulter, ajouter ou télécharger ses documents.
        </p>
      </div>

      <div className="mb-6 max-w-md">
        <Select label="Élève" value={eleveId} onChange={(e) => setEleveId(e.target.value)}>
          <option value="">— Sélectionner un élève —</option>
          {eleves?.map((el) => (
            <option key={el.id} value={el.id}>
              {el.prenom} {el.nom}
            </option>
          ))}
        </Select>
      </div>

      {eleveId ? (
        <div>
          <p className="mb-3 text-sm font-medium text-slate-600">
            Documents de {eleve?.prenom} {eleve?.nom}
          </p>
          <DocumentsPanel documentableType="eleve" documentableId={Number(eleveId)} />
        </div>
      ) : (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-white py-16 text-center">
          <Users size={32} className="mb-3 text-slate-300" />
          <p className="text-sm text-slate-400">Choisissez un élève pour afficher ses documents.</p>
        </div>
      )}
    </div>
  )
}
