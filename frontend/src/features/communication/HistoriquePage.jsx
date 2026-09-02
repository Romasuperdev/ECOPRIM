import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Input from '../../components/ui/Input'
import { fetchHistorique } from './communicationApi'

export default function HistoriquePage() {
  const [q, setQ] = useState('')
  const { data: lignes, isLoading } = useQuery({
    queryKey: ['historique-sms', q],
    queryFn: () => fetchHistorique(q),
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Historique des envois</h1>
        <p className="mt-1 text-sm text-slate-500">
          Journal des SMS transmis (source : ECONOMAT.T_SMS). L’historique n’est jamais effacé.
        </p>
      </div>

      <div className="mb-4 max-w-sm">
        <Input label="Rechercher" placeholder="Numéro ou contenu du message…" value={q}
               onChange={(e) => setQ(e.target.value)} />
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Heure</th>
              <th className="px-4 py-3 font-medium">Numéro</th>
              <th className="px-4 py-3 font-medium">Message</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Émis par</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>}
            {!isLoading && lignes?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucun envoi enregistré.</td></tr>
            )}
            {lignes?.map((l) => (
              <tr key={l.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 whitespace-nowrap text-slate-600">{l.date ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{l.heure ?? '—'}</td>
                <td className="px-4 py-3 font-mono text-xs text-slate-700">{l.numero ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{l.message}</td>
                <td className="px-4 py-3 text-slate-500">{l.type ?? '—'}</td>
                <td className="px-4 py-3 text-slate-500">{l.utilisateur ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
