import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Save } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import { fetchConseil, saveDeliberation } from './conseilsApi'

const DECISIONS = {
  passage: 'Passage',
  redoublement: 'Redoublement',
  orientation: 'Orientation',
  avertissement: 'Avertissement',
}

function DeliberationRow({ conseilId, eleve, existante }) {
  const queryClient = useQueryClient()
  const [values, setValues] = useState({
    moyenne_generale: existante?.moyenne_generale ?? '',
    decision: existante?.decision ?? '',
    appreciation: existante?.appreciation ?? '',
    mention: existante?.mention ?? '',
  })

  const mutation = useMutation({
    mutationFn: () =>
      saveDeliberation(conseilId, {
        eleve_id: eleve.id,
        moyenne_generale: values.moyenne_generale || null,
        decision: values.decision || null,
        appreciation: values.appreciation || null,
        mention: values.mention || null,
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['conseils', String(conseilId)] }),
  })

  return (
    <tr className="border-b border-slate-100 align-top">
      <td className="px-3 py-3 font-medium text-slate-800">
        {eleve.prenom} {eleve.nom}
      </td>
      <td className="px-3 py-3">
        <Input
          type="number"
          step="0.01"
          className="w-24"
          value={values.moyenne_generale}
          onChange={(e) => setValues((v) => ({ ...v, moyenne_generale: e.target.value }))}
        />
      </td>
      <td className="px-3 py-3">
        <Select
          className="w-40"
          value={values.decision}
          onChange={(e) => setValues((v) => ({ ...v, decision: e.target.value }))}
        >
          <option value="">—</option>
          {Object.entries(DECISIONS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
      </td>
      <td className="px-3 py-3">
        <Input
          className="w-28"
          value={values.mention}
          onChange={(e) => setValues((v) => ({ ...v, mention: e.target.value }))}
        />
      </td>
      <td className="px-3 py-3">
        <Textarea
          rows={2}
          className="min-w-[220px]"
          value={values.appreciation}
          onChange={(e) => setValues((v) => ({ ...v, appreciation: e.target.value }))}
        />
      </td>
      <td className="px-3 py-3">
        <Button variant="outline" onClick={() => mutation.mutate()} disabled={mutation.isPending}>
          <Save size={16} />
        </Button>
      </td>
    </tr>
  )
}

export default function ConseilDetailPage() {
  const { id } = useParams()

  const { data: conseil, isLoading } = useQuery({
    queryKey: ['conseils', id],
    queryFn: () => fetchConseil(id),
  })

  if (isLoading) return <p className="text-slate-400">Chargement…</p>
  if (!conseil) return null

  const deliberationsParEleve = Object.fromEntries(
    (conseil.deliberations ?? []).map((d) => [d.eleve_id, d])
  )

  return (
    <div>
      <Link to="/conseils-classe" className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
        <ArrowLeft size={16} /> Retour aux conseils
      </Link>
      <h1 className="mb-1 text-2xl font-bold text-slate-800">
        Conseil de classe — {conseil.classe?.nom}
      </h1>
      <p className="mb-6 text-slate-500">
        {conseil.periode?.libelle} · {conseil.date_conseil}
        {conseil.president && ` · Président : ${conseil.president}`}
        {conseil.secretaire && ` · Secrétaire : ${conseil.secretaire}`}
      </p>

      {conseil.observations_generales && (
        <div className="mb-6 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-2 text-sm font-semibold text-slate-700">Observations générales</h2>
          <p className="text-sm text-slate-600">{conseil.observations_generales}</p>
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-3 py-3 font-medium">Élève</th>
              <th className="px-3 py-3 font-medium">Moyenne</th>
              <th className="px-3 py-3 font-medium">Décision</th>
              <th className="px-3 py-3 font-medium">Mention</th>
              <th className="px-3 py-3 font-medium">Appréciation</th>
              <th className="px-3 py-3 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            {conseil.classe?.eleves?.map((eleve) => (
              <DeliberationRow
                key={eleve.id}
                conseilId={conseil.id}
                eleve={eleve}
                existante={deliberationsParEleve[eleve.id]}
              />
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
