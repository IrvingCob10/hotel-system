import api from './api'

export interface ExpenseRoom {
  id: number
  number: string
}

export interface Expense {
  id: number
  hotel_id: number
  room_id: number | null
  recorded_by_user_id: number | null
  category: string
  description: string
  amount: string | number
  expense_date: string
  payment_method: string | null
  vendor: string | null
  reference: string | null
  notes: string | null
  room?: ExpenseRoom | null
}

export interface ExpenseSummary {
  general_expenses: string | number
  room_expenses: string | number
  total_expenses: string | number
}

export interface RoomExpenseTotal {
  room_id: number
  room_number: string
  total: string | number
}

export interface ExpenseReport {
  hotel: {
    id: number
    name: string
  }
  summary: ExpenseSummary
  room_totals: RoomExpenseTotal[]
  expenses: Expense[]
}

export async function getExpenses(
  hotelId: number,
): Promise<ExpenseReport> {
  const response = await api.get<ExpenseReport>(
    `/hotels/${hotelId}/expenses`,
  )

  return response.data
}