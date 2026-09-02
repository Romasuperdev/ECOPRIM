import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Send, Mail, MailOpen, X, Plus } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { fetchMessages, fetchDestinataires, sendMessage, markMessageAsRead } from './messagesApi'

export default function MessagesPage() {
  const [boite, setBoite] = useState('reception')
  const [showForm, setShowForm] = useState(false)
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['messages', boite],
    queryFn: () => fetchMessages(boite),
  })
  const { data: destinataires } = useQuery({ queryKey: ['destinataires'], queryFn: fetchDestinataires })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm()

  const sendMutation = useMutation({
    mutationFn: sendMessage,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['messages'] })
      reset()
      setShowForm(false)
    },
  })

  const readMutation = useMutation({
    mutationFn: markMessageAsRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['messages'] }),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Messages</h1>
        <Button onClick={() => setShowForm((v) => !v)}>
          <span className="flex items-center gap-2">
            <Plus size={16} /> Nouveau message
          </span>
        </Button>
      </div>

      {showForm && (
        <form
          onSubmit={handleSubmit((values) => sendMutation.mutate(values))}
          className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-6"
        >
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">Nouveau message</h2>
            <button type="button" onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">
              <X size={18} />
            </button>
          </div>
          <Select
            label="Destinataire"
            error={errors.destinataire_id?.message}
            {...register('destinataire_id', { required: 'Le destinataire est requis' })}
          >
            <option value="">— Sélectionner —</option>
            {destinataires?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name} ({d.email})
              </option>
            ))}
          </Select>
          <Input label="Sujet" error={errors.sujet?.message} {...register('sujet', { required: 'Le sujet est requis' })} />
          <Textarea
            label="Message"
            rows={4}
            error={errors.contenu?.message}
            {...register('contenu', { required: 'Le message est requis' })}
          />
          <div className="flex justify-end">
            <Button type="submit" disabled={sendMutation.isPending}>
              <span className="flex items-center gap-2">
                <Send size={16} /> Envoyer
              </span>
            </Button>
          </div>
        </form>
      )}

      <div className="mb-4 flex gap-1 rounded-lg bg-slate-100 p-1 text-sm w-fit">
        <button
          onClick={() => setBoite('reception')}
          className={`rounded-md px-4 py-1.5 font-medium ${boite === 'reception' ? 'bg-white text-primary-700 shadow-sm' : 'text-slate-500'}`}
        >
          Réception
        </button>
        <button
          onClick={() => setBoite('envoyes')}
          className={`rounded-md px-4 py-1.5 font-medium ${boite === 'envoyes' ? 'bg-white text-primary-700 shadow-sm' : 'text-slate-500'}`}
        >
          Envoyés
        </button>
      </div>

      <div className="space-y-2">
        {isLoading && <p className="text-slate-400">Chargement…</p>}
        {!isLoading && data?.data.length === 0 && <p className="text-slate-400">Aucun message.</p>}
        {data?.data.map((message) => (
          <div
            key={message.id}
            className={`rounded-xl border border-slate-200 bg-white p-4 ${!message.lu_at && boite === 'reception' ? 'border-l-4 border-l-primary-500' : ''}`}
          >
            <div className="mb-1 flex items-center justify-between">
              <span className="flex items-center gap-2 font-semibold text-slate-800">
                {message.lu_at ? <MailOpen size={16} className="text-slate-400" /> : <Mail size={16} className="text-primary-600" />}
                {message.sujet}
              </span>
              <span className="text-xs text-slate-400">
                {new Date(message.created_at).toLocaleString('fr-FR')}
              </span>
            </div>
            <p className="mb-2 text-sm text-slate-600">{message.contenu}</p>
            <p className="text-xs text-slate-400">
              {boite === 'reception' ? `De : ${message.expediteur?.name}` : `À : ${message.destinataire?.name}`}
            </p>
            {boite === 'reception' && !message.lu_at && (
              <button
                onClick={() => readMutation.mutate(message.id)}
                className="mt-2 text-xs font-medium text-primary-700 hover:underline"
              >
                Marquer comme lu
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}
